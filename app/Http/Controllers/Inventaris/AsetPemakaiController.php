<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use App\Models\AsetWriteoff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AsetPemakaiController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * Disk tempat foto bukti serah-terima disimpan. Dipisah jadi konstanta
     * biar gampang diubah/di-swap tanpa nyari-nyari string 'public' di
     * banyak tempat.
     *
     * TETAP pakai disk 'public' (bukan S3) -- keputusan sadar, bukan lupa
     * diganti. Supaya file-nya nggak hilang lagi tiap redeploy/restart di
     * Railway (masalah sebelumnya), foldernya (storage/app/public, atau
     * seluruh storage/) di-mount ke Railway Volume supaya persisten. Detail
     * setup Volume-nya di luar file ini (Railway dashboard).
     *
     * SYARAT biar ini beneran persisten & bisa diakses publik:
     * 1. Railway Volume terpasang & di-mount ke path yang mencakup
     *    storage/app/public (mis. mount ke /app/storage).
     * 2. `php artisan storage:link` dijalankan tiap kali container baru naik
     *    (taruh di start command / release command Railway), karena symlink
     *    public/storage ikut hilang tiap image di-rebuild -- volume cuma
     *    nyelametin ISI foldernya, symlink-nya sendiri harus dibuat ulang.
     * 3. Service Railway TETAP 1 replica. Volume Railway nempel ke 1
     *    instance, jadi kalau di-scale ke >1 replica, upload yang masuk ke
     *    replica A nggak akan kebaca dari replica B (foto keliatan ilang2an
     *    lagi, sekarang penyebabnya beda: bukan ephemeral, tapi split
     *    antar-replica). Kalau nanti butuh scale >1, baru wajib pindah ke S3.
     */
    private const DISK_FOTO_BUKTI = 'public';

    /**
     * Simpan array file upload ke disk (default: 'public', lihat
     * DISK_FOTO_BUKTI di atas) dan kembalikan array path-nya. Dipakai bareng
     * buat foto_penerimaan (store) & foto_pengembalian (kembalikan) --
     * keduanya sama-sama "bukti serah terima" dalam bentuk array foto,
     * maksimal 3, disimpan sebagai JSON di kolom terkait.
     */
    private function simpanFotoBukti(Request $request, string $field, string $folder): array
    {
        $disk = config('filesystems.disk_aset', self::DISK_FOTO_BUKTI);

        $paths = [];
        foreach ($request->file($field, []) as $file) {
            $paths[] = $file->store($folder, $disk);
        }

        return $paths;
    }

    /**
     * GET /api/aset-pemakai/riwayat
     * Admin: riwayat GLOBAL semua aktivitas aset — pinjam, kembali, lapor
     * kerusakan, mulai perbaikan, selesai perbaikan, sampai dijual.
     * Karyawan/manajer/hr: riwayat DIBATASI cuma yang berhubungan sama diri
     * sendiri — pemakaian (pinjam/kembali) yang dia lakukan, plus laporan
     * kerusakan yang nempel di pemakaian dia. Event 'dijual' gak pernah
     * personal ke satu karyawan (itu keputusan admin di level aset), jadi
     * cuma muncul buat admin.
     *
     * Event pinjam/kembali mencakup baik aset utama maupun aset_kelengkapan
     * (dibedakan lewat kolom aset_kelengkapan_id di aset_pemakai). Event
     * lapor_rusak/mulai_perbaikan/selesai_perbaikan/dijual TETAP khusus aset
     * utama saja -- kelengkapan yang rusak cukup diubah statusnya manual
     * lewat AsetKelengkapanController::update(), tidak lewat alur
     * AsetPenanganan/AsetWriteoff.
     *
     * PENTING soal waktu: kolom tanggal_penerimaan/tanggal_pengembalian dan
     * tanggal_lapor/tanggal_diterima/tanggal_selesai cuma nyimpen TANGGAL
     * (jam-menit-detik selalu 00:00:00) — kalau dipakai langsung buat waktu
     * relatif ("X jam lalu") hasilnya ngaco (ngitung dari tengah malam, bukan
     * dari kejadian aslinya). Makanya di sini kita pakai kolom *_at
     * (diterima_at, dikembalikan_at, lapor_at, dst — datetime lengkap) yang
     * dicatat programnya sendiri, dengan fallback ke kolom tanggal_* lama
     * buat data lama yang belum punya *_at.
     */
    public function riwayat(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';
        $pekerjaId = $user?->pekerja?->id;

        // Pagination: minimal 10 data per halaman (dipaksa di server biar gak
        // ada yang kirim per_page kecil trus datanya keliatan bolong pas
        // digabung dari beberapa sumber). Filter 'type' opsional, dipakai
        // tab filter di frontend (Riwayat Aset) biar pagination-nya tetap
        // konsisten pas lagi difilter (bukan filter di halaman yang sudah
        // dipotong).
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, (int) $request->query('per_page', $request->query('limit', 10)));
        $typeFilter = $request->query('type');
        // Search bebas: cocok ke kode aset/kelengkapan, merek/tipe, ATAU nama
        // peminjam/pelapor. Dilakukan di memori (bareng filter type) SETELAH
        // events digabung, jadi tetap konsisten sama scoping kepemilikan di
        // bawah (user cuma nyari dalam data dia sendiri, gak bisa nembus ke
        // punya orang lain lewat search).
        $search = trim((string) $request->query('search', ''));

        // Ambil cukup banyak dari tiap sumber biar aman pas digabung+diurutkan
        // ulang di memori sebelum dipotong sesuai halaman yang diminta. Pas
        // lagi search, ambil lebih banyak lagi -- kalau enggak, pencarian
        // cuma nyisir data terbaru aja dan data lama yang cocok jadi ketutup.
        $ambil = $search !== '' ? 2000 : max(500, $page * $perPage * 2);

        $events = collect();

        // Filter kepemilikan dipakai berkali-kali di bawah — biar 1 sumber
        // kebenaran soal "ini punya user ini apa bukan", gak diketik ulang
        // beda-beda tiap query (rawan salah/kelewat kalau diketik manual).
        $milikUser = function ($query) use ($user, $pekerjaId) {
            $query->where(function ($q) use ($user, $pekerjaId) {
                $q->where('user_id', $user->id);
                if ($pekerjaId) {
                    $q->orWhere('pekerja_id', $pekerjaId);
                }
            });
        };

        $pemakaiQuery = AsetPemakai::with([
            'aset:id,kode_aset,merek,tipe',
            'asetKelengkapan:id,kode_kelengkapan,merek,tipe',
            'pekerja.user:id,name',
            'user:id,name', // akun cabang gak punya pekerja, jadi user-nya harus di-load langsung
        ])->where('status', 'disetujui');

        if (!$isAdmin) {
            $milikUser($pemakaiQuery);
        }

        $pemakaiQuery
            ->latest('tanggal_penerimaan')
            ->limit($ambil)
            ->get()
            ->each(function ($p) use (&$events) {
                $nama = $p->pekerja?->user?->name ?? $p->user?->name ?? '-';
                // item yang dipinjam bisa aset utama ATAU aset_kelengkapan,
                // gak pernah dua-duanya (mutually exclusive lewat kolom
                // aset_kelengkapan_id). 'tipe_item' dikirim ke frontend biar
                // tahu harus baca kode_aset atau kode_kelengkapan.
                $item = $p->aset ?? $p->asetKelengkapan;
                $tipeItem = $p->aset_kelengkapan_id ? 'kelengkapan' : 'aset';

                $events->push([
                    'type' => 'pinjam',
                    'waktu' => $p->diterima_at ?? $p->tanggal_penerimaan,
                    'nama' => $nama,
                    'aset' => $item,
                    'tipe_item' => $tipeItem,
                ]);
                if ($p->tanggal_pengembalian) {
                    $events->push([
                        'type' => 'kembali',
                        'waktu' => $p->dikembalikan_at ?? $p->tanggal_pengembalian,
                        'nama' => $nama,
                        'aset' => $item,
                        'tipe_item' => $tipeItem,
                    ]);
                }
            });

        $penangananQuery = AsetPenanganan::with([
            'aset:id,kode_aset,merek,tipe',
            'pemakai.pekerja.user:id,name',
            'pemakai.user:id,name', // akun cabang gak punya pekerja, jadi user-nya harus di-load langsung
        ]);

        if (!$isAdmin) {
            // laporan kerusakan gak punya user_id/pekerja_id langsung —
            // nempel ke AsetPemakai lewat aset_pemakai_id, jadi filternya
            // lewat relasi 'pemakai'. Laporan hasil audit gudang (pemakai
            // null) otomatis kepotong karena whereHas butuh relasi itu ada.
            $penangananQuery->whereHas('pemakai', $milikUser);
        }

        $penangananQuery
            ->latest('tanggal_lapor')
            ->limit($ambil)
            ->get()
            ->each(function ($pn) use (&$events) {
                // nama pelapor: sama kayak event pinjam, ambil dari
                // pemakai->pekerja->user atau pemakai->user. Kalau
                // aset_pemakai_id null (lapor pas aset lagi nganggur/audit
                // gudang), nama emang gak ada -- tampilin '-' di frontend.
                //
                // PENTING: nama ini cuma valid buat event 'lapor_rusak'
                // (itu aksi si pemakai). 'mulai_perbaikan' & 'selesai_perbaikan'
                // itu aksinya ADMIN (klik "Terima"/"Tandai Selesai"), bukan
                // pemakai -- jadi jangan pakai nama pemakai di situ, biar gak
                // kelihatan seolah pemakai yang benerin asetnya sendiri.
                // (Belum ada kolom yang nyimpen admin mana yang ngerjain,
                // makanya nama dikosongin aja dulu, bukan salah orang.)
                $namaPelapor = $pn->pemakai?->pekerja?->user?->name ?? $pn->pemakai?->user?->name ?? null;

                $events->push([
                    'type' => 'lapor_rusak',
                    'waktu' => $pn->lapor_at ?? $pn->tanggal_lapor,
                    'nama' => $namaPelapor,
                    'aset' => $pn->aset,
                    'tipe_item' => 'aset',
                    'keluhan' => $pn->keluhan,
                ]);
                if ($pn->tanggal_diterima) {
                    $events->push([
                        'type' => 'mulai_perbaikan',
                        'waktu' => $pn->diterima_at ?? $pn->tanggal_diterima,
                        'nama' => null,
                        'aset' => $pn->aset,
                        'tipe_item' => 'aset',
                    ]);
                }
                if ($pn->tanggal_selesai) {
                    $events->push([
                        'type' => 'selesai_perbaikan',
                        'waktu' => $pn->selesai_at ?? $pn->tanggal_selesai,
                        'nama' => null,
                        'aset' => $pn->aset,
                        'tipe_item' => 'aset',
                        'hasil' => $pn->hasil,
                    ]);
                }
            });

        // 'dijual' cuma buat admin — keputusan writeoff bukan aktivitas
        // pribadi karyawan mana pun, gak relevan buat riwayat pribadinya.
        // Writeoff/jual cuma dikenal buat aset utama, kelengkapan gak punya
        // alur ini.
        if ($isAdmin) {
            AsetWriteoff::with(['aset:id,kode_aset,merek,tipe', 'penyetuju:id,name'])
                ->latest('tanggal_writeoff')
                ->limit($ambil)
                ->get()
                ->each(function ($w) use (&$events) {
                    $events->push([
                        'type' => 'dijual',
                        // created_at datetime lengkap — writeoff cuma dibuat sekali
                        // (updateOrCreate), jadi aman dipakai apa adanya.
                        'waktu' => $w->created_at ?? $w->tanggal_writeoff,
                        'nama' => $w->penyetuju?->name,
                        'aset' => $w->aset,
                        'tipe_item' => 'aset',
                        'keluhan' => $w->alasan,
                    ]);
                });
        }

        $sorted = $events
            ->sortByDesc(fn ($ev) => $ev['waktu'] instanceof \Carbon\Carbon ? $ev['waktu'] : \Carbon\Carbon::parse($ev['waktu']))
            ->values();

        if ($typeFilter) {
            $sorted = $sorted->where('type', $typeFilter)->values();
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $sorted = $sorted->filter(function ($ev) use ($needle) {
                $aset = $ev['aset'] ?? null;
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $ev['nama'] ?? '',
                    $aset?->kode_aset ?? '',
                    $aset?->kode_kelengkapan ?? '',
                    $aset?->merek ?? '',
                    $aset?->tipe ?? '',
                ])));

                return str_contains($haystack, $needle);
            })->values();
        }

        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $data = $sorted->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /aset-pemakai/foto — list semua record serah-terima/pengembalian yang punya foto,
     * buat halaman galeri. Admin lihat semua; role lain cuma punya sendiri.
     * Search & eager-load mencakup baik aset utama maupun aset_kelengkapan.
     */
    public function foto(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        // type dipakai buat pisahin tab "Peminjaman" vs "Pengembalian" di
        // halaman Foto Aset -- masing-masing punya pagination sendiri jadi
        // gak nyampur kayak dulu (satu entri bisa punya dua-duanya sekaligus).
        $type = $request->input('type');

        $query = AsetPemakai::with(['aset', 'asetKelengkapan', 'pekerja.user', 'user'])
            ->when(
                $type === 'peminjaman',
                fn ($q) => $q->whereNotNull('foto_penerimaan'),
                fn ($q) => $q->when(
                    $type === 'pengembalian',
                    fn ($q2) => $q2->whereNotNull('foto_pengembalian'),
                    fn ($q2) => $q2->where(function ($qq) {
                        $qq->whereNotNull('foto_penerimaan')
                            ->orWhereNotNull('foto_pengembalian');
                    })
                )
            )
            ->orderByDesc('created_at');

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('pekerja', fn ($qq) => $qq->where('user_id', $user->id))
                    ->orWhere('user_id', $user->id);
            });
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('aset', function ($qq) use ($search) {
                    $qq->where('kode_aset', 'like', "%{$search}%")
                        ->orWhere('merek', 'like', "%{$search}%")
                        ->orWhere('tipe', 'like', "%{$search}%");
                })->orWhereHas('asetKelengkapan', function ($qq) use ($search) {
                    $qq->where('kode_kelengkapan', 'like', "%{$search}%")
                        ->orWhere('merek', 'like', "%{$search}%")
                        ->orWhere('tipe', 'like', "%{$search}%");
                });
            });
        }

        $perPage = max(10, (int) $request->input('per_page', 12));
        $data = $query->paginate($perPage);

        return response()->json($data);
    }

    /**
     * POST /aset/{aset}/pemakai
     * Admin serah-terima aset utama langsung ke pekerja ATAU akun cabang
     * (tanpa lewat alur request/approve). Lihat serahkanItem() untuk logic
     * lengkapnya (dipakai bareng sama storeKelengkapan()).
     */
    public function store(Request $request, Aset $aset)
    {
        return $this->serahkanItem($request, 'aset_id', $aset, $aset->status);
    }

    /**
     * POST /aset-kelengkapan/{asetKelengkapan}/pemakai
     * DIHAPUS DARI ALUR NORMAL: kelengkapan (tas, charger, dst) TIDAK BISA
     * dipinjamkan sendiri/berdiri sendiri lagi -- dia wajib nempel & ikut
     * status aset induknya. Begitu aset induk dipinjamkan lewat store(),
     * semua kelengkapan induk itu yang lagi 'tersedia' otomatis ikut
     * dipinjamkan bareng (lihat serahkanItem()) dan otomatis ikut kembali
     * pas induknya dikembalikan (lihat kembalikan()). Endpoint ini
     * dipertahankan (bukan dihapus routenya) supaya kalau ada pemanggil
     * lama nggak 500, tapi sengaja selalu ditolak di sini.
     */
    public function storeKelengkapan(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        return response()->json([
            'message' => 'Kelengkapan tidak bisa dipinjamkan sendiri. Pinjamkan aset utamanya -- kelengkapan yang tersedia akan otomatis ikut dipinjamkan.',
        ], 422);
    }

    /**
     * Logic serah-terima yang dipakai bareng oleh store() & storeKelengkapan()
     * -- satu-satunya beda antara aset utama dan kelengkapan cuma kolom FK
     * mana yang diisi di aset_pemakai ($kolomId: 'aset_id' atau
     * 'aset_kelengkapan_id'). Wajib sertakan foto_penerimaan (array file,
     * 1-3 foto) sebagai bukti fisik serah-terima. Disimpan sebagai array
     * path JSON, BUKAN kolom foto_1/foto_2/foto_3 terpisah. Filenya
     * disimpan ke disk 'public' yang di-mount ke Railway Volume (lihat
     * catatan di simpanFotoBukti()) supaya persisten antar-redeploy.
     */
    private function serahkanItem(Request $request, string $kolomId, $item, string $statusSaatIni)
    {
        if ($statusSaatIni !== 'tersedia') {
            return response()->json(['message' => 'Item ini sedang tidak tersedia untuk diserahkan.'], 422);
        }

        $validated = $request->validate([
            'pekerja_id' => 'required_without:user_id|nullable|exists:pekerja,id',
            'user_id' => [
                'required_without:pekerja_id',
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value && \App\Models\User::where('id', $value)->where('role', 'cabang')->doesntExist()) {
                        $fail('Akun yang dipilih bukan akun cabang.');
                    }
                },
            ],
            'tanggal_penerimaan' => 'required|date',
            'catatan_penerimaan' => 'nullable|string',
            'foto_penerimaan' => 'required|array|min:1|max:3',
            'foto_penerimaan.*' => 'image|mimes:jpg,jpeg,png,webp|max:1024',
        ], [
            'foto_penerimaan.*.max' => 'Maksimal size foto adalah 1MB',
        ]);

        $pemakai = DB::transaction(function () use ($item, $request, $validated, $kolomId) {
            $noStruk = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_penerimaan', 'aset-pemakai/penerimaan');

            $pemakai = AsetPemakai::create([
                $kolomId => $item->id,
                'pekerja_id' => $validated['pekerja_id'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'status' => 'disetujui',
                'requested_by_user_id' => $request->user()?->id,
                'no_struk_penerimaan' => $noStruk,
                'tanggal_penerimaan' => $validated['tanggal_penerimaan'],
                'diterima_at' => now(),
                'catatan_penerimaan' => $validated['catatan_penerimaan'] ?? null,
                'foto_penerimaan' => $fotoPaths,
            ]);

            $item->update(['status' => 'dipakai']);

            // Kelengkapan wajib ikut aset induknya -- begitu aset utama
            // dipinjamkan, semua kelengkapan miliknya yang masih 'tersedia'
            // otomatis ikut dipinjamkan ke pemakai yang sama, pakai foto
            // bukti & tanggal serah-terima yang sama juga (satu kejadian
            // serah-terima fisik, bukan kejadian terpisah-pisah). Kelengkapan
            // TIDAK bisa dipinjam lewat jalur lain lagi (lihat
            // storeKelengkapan()), jadi ini satu-satunya cara kelengkapan
            // pindah status ke 'dipakai'.
            if ($kolomId === 'aset_id') {
                $item->asetKelengkapan()
                    ->where('status', 'tersedia')
                    ->get()
                    ->each(function ($kelengkapan) use ($validated, $fotoPaths, $request) {
                        $noStrukKelengkapan = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

                        AsetPemakai::create([
                            'aset_kelengkapan_id' => $kelengkapan->id,
                            'pekerja_id' => $validated['pekerja_id'] ?? null,
                            'user_id' => $validated['user_id'] ?? null,
                            'status' => 'disetujui',
                            'requested_by_user_id' => $request->user()?->id,
                            'no_struk_penerimaan' => $noStrukKelengkapan,
                            'tanggal_penerimaan' => $validated['tanggal_penerimaan'],
                            'diterima_at' => now(),
                            'catatan_penerimaan' => 'Dipinjamkan otomatis -- mengikuti aset induk.',
                            'foto_penerimaan' => $fotoPaths,
                        ]);

                        $kelengkapan->update(['status' => 'dipakai']);
                    });
            }

            return $pemakai;
        });

        return response()->json($pemakai->load('pekerja.user', 'user', 'aset', 'asetKelengkapan'), 201);
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/kembalikan
     * Admin terima kembali aset/kelengkapan dari pemakai, ATAU pemakainya
     * sendiri (karyawan/cabang yang lagi pegang item ini) yang ngembaliin
     * langsung. Wajib sertain no_struk_penerimaan (struk asli pas
     * serah-terima) sebagai bukti pengembalian ini benar. Ditolak kalau
     * masih ada laporan penanganan/perbaikan yang belum selesai (guard ini
     * relevan buat aset utama; aset_pemakai_id kelengkapan gak akan pernah
     * punya AsetPenanganan karena kelengkapan gak lewat alur itu, jadi query
     * ini otomatis gak nemu apa-apa buat kelengkapan).
     *
     * Wajib sertakan foto_pengembalian (array file, 1-3 foto) sebagai bukti
     * kondisi fisik item saat dikembalikan. Filenya disimpan ke disk
     * 'public' yang di-mount ke Railway Volume (lihat catatan di
     * simpanFotoBukti()) supaya persisten antar-redeploy.
     */
    public function kembalikan(Request $request, AsetPemakai $asetPemakai)
    {
        $user = $request->user();
        $isPemilikPemakaian = ($asetPemakai->pekerja?->user_id === $user->id)
            || ($asetPemakai->user_id === $user->id);

        abort_unless(
            $user->hasRoleAtLeast('admin') || $isPemilikPemakaian,
            403,
            'Kamu tidak punya akses untuk mengembalikan item ini.'
        );

        $validated = $request->validate([
            'no_struk_penerimaan' => 'required|string',
            'tanggal_pengembalian' => 'required|date',
            'catatan_pengembalian' => 'nullable|string',
            'foto_pengembalian' => 'required|array|min:1|max:3',
            'foto_pengembalian.*' => 'image|mimes:jpg,jpeg,png,webp|max:1024',
        ], [
            'foto_pengembalian.*.max' => 'Maksimal size foto adalah 1MB',
        ]);

        if ($asetPemakai->status !== 'disetujui') {
            throw ValidationException::withMessages([
                'status' => ['Data pemakaian ini bukan pemakaian aktif yang bisa dikembalikan.'],
            ]);
        }

        if ($validated['no_struk_penerimaan'] !== $asetPemakai->no_struk_penerimaan) {
            throw ValidationException::withMessages([
                'no_struk_penerimaan' => ['Nomor struk penerimaan tidak cocok. Pengembalian wajib menyertakan bukti serah-terima yang benar.'],
            ]);
        }

        $adaPenangananBelumSelesai = AsetPenanganan::where('aset_pemakai_id', $asetPemakai->id)
            ->whereNull('tanggal_selesai')
            ->exists();

        if ($adaPenangananBelumSelesai) {
            throw ValidationException::withMessages([
                'penanganan' => ['Masih ada laporan penanganan/perbaikan yang belum diselesaikan untuk pemakaian ini.'],
            ]);
        }

        DB::transaction(function () use ($asetPemakai, $request, $validated) {
            $noStruk = $this->generateNoStruk('KBL', 'aset_pemakai', 'no_struk_pengembalian');
            $isKelengkapan = $asetPemakai->aset_kelengkapan_id !== null;

            // 'rusak_berat' cuma dikenal di status aset UTAMA (lihat enum di
            // AsetController::validasi) -- aset_kelengkapan cuma punya
            // tersedia/dipakai/rusak/diperbaiki, jadi gak ada state permanen
            // yang perlu di-guard buat kelengkapan.
            $catatanDefault = $asetPemakai->catatan_pengembalian;
            if (!$isKelengkapan && $asetPemakai->aset?->status === 'rusak_berat') {
                $catatanDefault = 'Dikembalikan dalam kondisi rusak berat (tidak bisa diperbaiki).';
            }

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_pengembalian', 'aset-pemakai/pengembalian');

            $asetPemakai->update([
                'no_struk_pengembalian' => $noStruk,
                'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                'dikembalikan_at' => now(),
                'catatan_pengembalian' => $validated['catatan_pengembalian'] ?? $catatanDefault,
                'foto_pengembalian' => $fotoPaths,
            ]);

            // jangan paksa balik 'tersedia' kalau asetnya lagi rusak_berat --
            // dia tetep gak boleh dipinjemin lagi walau pemakaiannya udah
            // ditutup. Kelengkapan gak punya state itu, jadi selalu balik
            // normal ke 'tersedia'.
            if ($isKelengkapan) {
                $asetPemakai->asetKelengkapan()->update(['status' => 'tersedia']);
            } else {
                $asetPemakai->aset()
                    ->where('status', '!=', 'rusak_berat')
                    ->update(['status' => 'tersedia']);

                // Kelengkapan ikut aset induknya juga pas dikembalikan --
                // semua kelengkapan aset ini yang masih aktif dipinjam
                // (disetujui, belum ada tanggal_pengembalian) ditutup
                // bareng, pakai foto & tanggal pengembalian yang sama
                // dengan induknya (satu kejadian serah-terima fisik).
                $noStrukIndukKembali = $asetPemakai->no_struk_pengembalian;
                AsetPemakai::where('aset_kelengkapan_id', function ($q) use ($asetPemakai) {
                    $q->select('id')
                        ->from('aset_kelengkapan')
                        ->where('aset_id', $asetPemakai->aset_id);
                })
                    ->where('status', 'disetujui')
                    ->whereNull('tanggal_pengembalian')
                    ->get()
                    ->each(function ($kelengkapanPemakai) use ($validated, $fotoPaths, $noStrukIndukKembali) {
                        $noStrukKelengkapan = $this->generateNoStruk('KBL', 'aset_pemakai', 'no_struk_pengembalian');

                        $kelengkapanPemakai->update([
                            'no_struk_pengembalian' => $noStrukKelengkapan,
                            'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                            'dikembalikan_at' => now(),
                            'catatan_pengembalian' => 'Dikembalikan otomatis -- mengikuti aset induk (' . $noStrukIndukKembali . ').',
                            'foto_pengembalian' => $fotoPaths,
                        ]);

                        $kelengkapanPemakai->asetKelengkapan()->update(['status' => 'tersedia']);
                    });
            }
        });

        return response()->json($asetPemakai->fresh()->load('pekerja.user', 'user', 'aset', 'asetKelengkapan'));
    }

    /**
     * DELETE /api/aset-pemakai/{asetPemakai}
     * Admin: hapus satu entri riwayat pemakaian (aset utama maupun
     * kelengkapan). Ditolak kalau entri ini punya laporan
     * penanganan/perbaikan yang nempel (aset_pemakai_id) -- guard ini secara
     * alami cuma pernah kena buat aset utama, karena kelengkapan gak lewat
     * alur AsetPenanganan. Kalau entri yang dihapus ini pemakaian yang masih
     * aktif (belum dikembalikan), status item-nya dikembalikan ke 'tersedia'
     * dulu (kecuali aset utama lagi rusak_berat -- tetap gak boleh
     * dipinjemin lagi).
     */
    public function destroy(AsetPemakai $asetPemakai)
    {
        if (AsetPenanganan::where('aset_pemakai_id', $asetPemakai->id)->exists()) {
            return response()->json([
                'message' => 'Riwayat pemakaian ini punya laporan perbaikan terkait dan tidak bisa dihapus.',
            ], 422);
        }

        $masihDipakai = $asetPemakai->status === 'disetujui' && !$asetPemakai->tanggal_pengembalian;
        $isKelengkapan = $asetPemakai->aset_kelengkapan_id !== null;

        DB::transaction(function () use ($asetPemakai, $masihDipakai, $isKelengkapan) {
            if ($masihDipakai) {
                if ($isKelengkapan) {
                    $asetPemakai->asetKelengkapan()->update(['status' => 'tersedia']);
                } else {
                    $asetPemakai->aset()
                        ->where('status', '!=', 'rusak_berat')
                        ->update(['status' => 'tersedia']);

                    // Sama seperti kembalikan(): entri kelengkapan yang masih
                    // aktif ikut aset ini juga ikut dilepas & ditutup statusnya
                    // (bukan dihapus, biar riwayatnya tetap ada), biar gak
                    // nyangkut "dipakai" padahal riwayat pinjam aset induknya
                    // udah dihapus.
                    AsetPemakai::where('aset_kelengkapan_id', function ($q) use ($asetPemakai) {
                        $q->select('id')
                            ->from('aset_kelengkapan')
                            ->where('aset_id', $asetPemakai->aset_id);
                    })
                        ->where('status', 'disetujui')
                        ->whereNull('tanggal_pengembalian')
                        ->get()
                        ->each(function ($kelengkapanPemakai) {
                            $kelengkapanPemakai->update([
                                'tanggal_pengembalian' => now()->toDateString(),
                                'dikembalikan_at' => now(),
                                'catatan_pengembalian' => 'Dikembalikan otomatis -- riwayat pinjam aset induk dihapus.',
                            ]);
                            $kelengkapanPemakai->asetKelengkapan()->update(['status' => 'tersedia']);
                        });
                }
            }
            $asetPemakai->delete();
        });

        return response()->json(['message' => 'Riwayat pemakaian berhasil dihapus.']);
    }
}