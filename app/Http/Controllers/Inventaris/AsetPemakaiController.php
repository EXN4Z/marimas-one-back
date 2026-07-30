<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
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
        // Search bebas: cocok ke kode aset, merek/tipe aset, ATAU nama
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
                $events->push([
                    'type' => 'pinjam',
                    'waktu' => $p->diterima_at ?? $p->tanggal_penerimaan,
                    'nama' => $nama,
                    'aset' => $p->aset,
                ]);
                if ($p->tanggal_pengembalian) {
                    $events->push([
                        'type' => 'kembali',
                        'waktu' => $p->dikembalikan_at ?? $p->tanggal_pengembalian,
                        'nama' => $nama,
                        'aset' => $p->aset,
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
                    'keluhan' => $pn->keluhan,
                ]);
                if ($pn->tanggal_diterima) {
                    $events->push([
                        'type' => 'mulai_perbaikan',
                        'waktu' => $pn->diterima_at ?? $pn->tanggal_diterima,
                        'nama' => null,
                        'aset' => $pn->aset,
                    ]);
                }
                if ($pn->tanggal_selesai) {
                    $events->push([
                        'type' => 'selesai_perbaikan',
                        'waktu' => $pn->selesai_at ?? $pn->tanggal_selesai,
                        'nama' => null,
                        'aset' => $pn->aset,
                        'hasil' => $pn->hasil,
                    ]);
                }
            });

        // 'dijual' cuma buat admin — keputusan writeoff bukan aktivitas
        // pribadi karyawan mana pun, gak relevan buat riwayat pribadinya.
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
     * POST /aset/{aset}/pemakai
     * Admin serah-terima aset langsung ke pekerja ATAU akun cabang (tanpa lewat
     * alur request/approve). Kirim salah satu: pekerja_id (karyawan) atau
     * user_id (akun cabang) — nggak boleh dua-duanya, nggak boleh kosong dua-duanya.
     * Aset harus 'tersedia'. Struk penerimaan digenerate otomatis.
     *
     * Wajib sertakan foto_penerimaan (array file, 1-3 foto) sebagai bukti
     * fisik serah-terima. Disimpan sebagai array path JSON, BUKAN kolom
     * foto_1/foto_2/foto_3 terpisah. Filenya disimpan ke disk 'public' yang
     * di-mount ke Railway Volume (lihat catatan di simpanFotoBukti()) supaya
     * persisten antar-redeploy.
     */
    // GET /aset-pemakai/foto — list semua record serah-terima/pengembalian yang punya foto,
// buat halaman galeri. Admin lihat semua; role lain cuma punya sendiri.
    public function foto(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $query = AsetPemakai::with(['aset.jenis', 'pekerja.user', 'user'])
            ->where(function ($q) {
                $q->whereNotNull('foto_penerimaan')
                ->orWhereNotNull('foto_pengembalian');
            })
            ->orderByDesc('created_at');

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('pekerja', fn ($qq) => $qq->where('user_id', $user->id))
                ->orWhere('user_id', $user->id);
            });
        }

        if ($search = $request->input('search')) {
            $query->whereHas('aset', function ($q) use ($search) {
                $q->where('kode_aset', 'like', "%{$search}%")
                ->orWhere('merek', 'like', "%{$search}%")
                ->orWhere('tipe', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, (int) $request->input('per_page', 12));
        $data = $query->paginate($perPage);

        return response()->json($data);
    }
    public function store(Request $request, Aset $aset)
    {
        if ($aset->status !== 'tersedia') {
            return response()->json(['message' => 'Aset ini sedang tidak tersedia untuk diserahkan.'], 422);
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
            'foto_penerimaan.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $pemakai = DB::transaction(function () use ($aset, $request, $validated) {
            $noStruk = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_penerimaan', 'aset-pemakai/penerimaan');

            $pemakai = AsetPemakai::create([
                'aset_id' => $aset->id,
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

            $aset->update(['status' => 'dipakai']);

            return $pemakai;
        });

        return response()->json($pemakai->load('pekerja.user', 'user', 'aset'), 201);
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/kembalikan
     * Admin terima kembali aset dari pemakai, ATAU pemakainya sendiri
     * (karyawan/cabang yang lagi pegang aset ini) yang ngembaliin langsung.
     * Wajib sertain no_struk_penerimaan (struk asli pas serah-terima) sebagai
     * bukti pengembalian ini benar. Ditolak kalau masih ada laporan
     * penanganan/perbaikan yang belum selesai.
     *
     * Wajib sertakan foto_pengembalian (array file, 1-3 foto) sebagai bukti
     * kondisi fisik aset saat dikembalikan. Filenya disimpan ke disk
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
            'Kamu tidak punya akses untuk mengembalikan aset ini.'
        );

        $validated = $request->validate([
            'no_struk_penerimaan' => 'required|string',
            'tanggal_pengembalian' => 'required|date',
            'catatan_pengembalian' => 'nullable|string',
            'foto_pengembalian' => 'required|array|min:1|max:3',
            'foto_pengembalian.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
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

            // kalau aset ini pernah rusak berat & udah ditandai selesai perbaikan
            // (hasil tetep rusak_berat, gak ketolong), catatan pengembalian default
            // kasih tau kondisinya -- kecuali admin udah isi catatan sendiri.
            $asetRusakBerat = $asetPemakai->aset?->status === 'rusak_berat';
            $catatanDefault = $asetRusakBerat
                ? 'Dikembalikan dalam kondisi rusak berat (tidak bisa diperbaiki).'
                : $asetPemakai->catatan_pengembalian;

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_pengembalian', 'aset-pemakai/pengembalian');

            $asetPemakai->update([
                'no_struk_pengembalian' => $noStruk,
                'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                'dikembalikan_at' => now(),
                'catatan_pengembalian' => $validated['catatan_pengembalian'] ?? $catatanDefault,
                'foto_pengembalian' => $fotoPaths,
            ]);

            // jangan paksa balik 'tersedia' kalau asetnya lagi rusak_berat --
            // dia tetep gak boleh dipinjemin lagi walau pemakaiannya udah ditutup.
            // Selain rusak_berat, baru balik normal ke 'tersedia' kayak biasa.
            $asetPemakai->aset()
                ->where('status', '!=', 'rusak_berat')
                ->update(['status' => 'tersedia']);
        });

        return response()->json($asetPemakai->fresh()->load('pekerja.user', 'user', 'aset'));
    }
}