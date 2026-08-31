<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\MasterData\Inventory;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryPenanganan;
use App\Models\Transaksi\InventoryWriteoff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryPemakaiController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * Disk tempat foto bukti serah-terima disimpan. Dipisah jadi konstanta
     * biar gampang diubah/di-swap tanpa nyari-nyari string 'public' di
     * banyak tempat.
     *
     * TETAP pakai disk 'public' (bukan S3) -- keputusan sadar, bukan lupa
     * diganti. Foldernya (storage/app/public, atau seluruh storage/)
     * di-mount ke Railway Volume supaya persisten antar-redeploy. Lihat
     * catatan lengkap di versi lama (AsetPemakaiController) soal syarat
     * volume + storage:link + kenapa harus tetap 1 replica.
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
     * GET /api/inventory-pemakai/riwayat
     * Admin: riwayat GLOBAL semua aktivitas inventory — pinjam, kembali,
     * lapor kerusakan, mulai perbaikan, selesai perbaikan, sampai dijual.
     * Karyawan/manajer/hr: riwayat DIBATASI cuma yang berhubungan sama diri
     * sendiri — pemakaian (pinjam/kembali) yang dia lakukan, plus laporan
     * kerusakan yang nempel di pemakaian dia. Event 'dijual' gak pernah
     * personal ke satu karyawan (itu keputusan admin di level barang), jadi
     * cuma muncul buat admin.
     *
     * Event pinjam/kembali sekarang mencakup baik Barang Utama maupun
     * Kelengkapan lewat satu relasi `inventory` yang sama (dulu dipisah
     * lewat kolom aset_kelengkapan_id) -- `tipe_item` di payload diturunkan
     * dari kategori item itu sendiri. Event
     * lapor_rusak/mulai_perbaikan/selesai_perbaikan/dijual TETAP khusus
     * Barang Utama saja -- Kelengkapan yang rusak cukup diubah statusnya
     * lewat InventoryController::laporRusakKelengkapan(), tidak lewat alur
     * InventoryPenanganan/InventoryWriteoff.
     *
     * PENTING soal waktu: kolom tanggal_penerimaan/tanggal_pengembalian dan
     * tanggal_lapor/tanggal_diterima/tanggal_selesai cuma nyimpen TANGGAL
     * (jam-menit-detik selalu 00:00:00) — kalau dipakai langsung buat waktu
     * relatif ("X jam lalu") hasilnya ngaco. Makanya di sini kita pakai
     * kolom *_at (diterima_at, dikembalikan_at, lapor_at, dst — datetime
     * lengkap) yang dicatat programnya sendiri, dengan fallback ke kolom
     * tanggal_* lama buat data lama yang belum punya *_at.
     */

    public function index()
    {
        $data = InventoryPemakai::with(['inventory', 'user'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($data);
    }

    public function riwayat(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, (int) $request->query('per_page', $request->query('limit', 10)));
        $typeFilter = $request->query('type');
        $search = trim((string) $request->query('search', ''));

        $ambil = $search !== '' ? 2000 : max(500, $page * $perPage * 2);

        $events = collect();

        $milikUser = function ($query) use ($user) {
            $query->where('user_id', $user->id);
        };

        $pemakaiQuery = InventoryPemakai::with([
            'inventory:id,kode_inventory,nama,kategori_id',
            'inventory.kategori:id,nama',
            'user:id,name',
        ])->where('status', 'disetujui');

        if (!$isAdmin) {
            $milikUser($pemakaiQuery);
        }

        $pemakaiQuery
            ->latest('tanggal_penerimaan')
            ->limit($ambil)
            ->get()
            ->each(function ($p) use (&$events) {
                $nama = $p->user?->name ?? '-';
                $tipeItem = $p->inventory?->isKelengkapan() ? 'kelengkapan' : 'barang_utama';

                $events->push([
                    'type' => 'pinjam',
                    'waktu' => $p->diterima_at ?? $p->tanggal_penerimaan,
                    'nama' => $nama,
                    'inventory' => $p->inventory,
                    'tipe_item' => $tipeItem,
                ]);
                if ($p->tanggal_pengembalian) {
                    $events->push([
                        'type' => 'kembali',
                        'waktu' => $p->dikembalikan_at ?? $p->tanggal_pengembalian,
                        'nama' => $nama,
                        'inventory' => $p->inventory,
                        'tipe_item' => $tipeItem,
                    ]);
                }
            });

        $penangananQuery = InventoryPenanganan::with([
            'inventory:id,kode_inventory,nama',
            'pemakai.user:id,name',
        ]);

        if (!$isAdmin) {
            // laporan kerusakan gak punya user_id langsung — nempel ke
            // InventoryPemakai lewat inventory_pemakai_id, jadi filternya
            // lewat relasi 'pemakai'. Laporan hasil audit gudang (pemakai
            // null) otomatis kepotong karena whereHas butuh relasi itu ada.
            $penangananQuery->whereHas('pemakai', $milikUser);
        }

        $penangananQuery
            ->latest('tanggal_lapor')
            ->limit($ambil)
            ->get()
            ->each(function ($pn) use (&$events) {
                $namaPelapor = $pn->pemakai?->user?->name ?? null;

                $events->push([
                    'type' => 'lapor_rusak',
                    'waktu' => $pn->lapor_at ?? $pn->tanggal_lapor,
                    'nama' => $namaPelapor,
                    'inventory' => $pn->inventory,
                    'tipe_item' => 'barang_utama',
                    'keluhan' => $pn->keluhan,
                ]);
                if ($pn->tanggal_diterima) {
                    $events->push([
                        'type' => 'mulai_perbaikan',
                        'waktu' => $pn->diterima_at ?? $pn->tanggal_diterima,
                        'nama' => null,
                        'inventory' => $pn->inventory,
                        'tipe_item' => 'barang_utama',
                    ]);
                }
                if ($pn->tanggal_selesai) {
                    $events->push([
                        'type' => 'selesai_perbaikan',
                        'waktu' => $pn->selesai_at ?? $pn->tanggal_selesai,
                        'nama' => null,
                        'inventory' => $pn->inventory,
                        'tipe_item' => 'barang_utama',
                        'hasil' => $pn->hasil,
                    ]);
                }
            });

        // 'dijual' cuma buat admin — keputusan writeoff bukan aktivitas
        // pribadi karyawan mana pun, gak relevan buat riwayat pribadinya.
        // Writeoff/jual cuma dikenal buat Barang Utama.
        if ($isAdmin) {
            InventoryWriteoff::with(['inventory:id,kode_inventory,nama', 'penyetuju:id,name'])
                ->latest('tanggal_writeoff')
                ->limit($ambil)
                ->get()
                ->each(function ($w) use (&$events) {
                    $events->push([
                        'type' => 'dijual',
                        'waktu' => $w->created_at ?? $w->tanggal_writeoff,
                        'nama' => $w->penyetuju?->name,
                        'inventory' => $w->inventory,
                        'tipe_item' => 'barang_utama',
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
                $item = $ev['inventory'] ?? null;
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $ev['nama'] ?? '',
                    $item?->kode_inventory ?? '',
                    $item?->nama ?? '',
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
     * GET /api/inventory-pemakai/foto — list semua record serah-terima/
     * pengembalian yang punya foto, buat halaman galeri. Admin lihat semua;
     * role lain cuma punya sendiri.
     */
    public function foto(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $type = $request->input('type');

        $query = InventoryPemakai::with(['inventory', 'user'])
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
            $query->where('user_id', $user->id);
        }

        if ($search = $request->input('search')) {
            $query->whereHas('inventory', function ($qq) use ($search) {
                $qq->where('kode_inventory', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, (int) $request->input('per_page', 12));
        $data = $query->paginate($perPage);

        return response()->json($data);
    }

    /**
     * POST /api/inventory/{inventory}/pemakai
     * Admin serah-terima item langsung ke karyawan ATAU akun cabang (tanpa
     * lewat alur request/approve). Bisa dipakai buat Barang Utama maupun
     * Kelengkapan yang berdiri sendiri (parent_id null) -- Kelengkapan yang
     * sudah menempel ke Barang Utama (parent_id terisi) DITOLAK di sini,
     * karena dia cuma boleh ikut pinjam bareng induknya (lihat rule #1 di
     * dokumen migrasi).
     */
    public function store(Request $request, Inventory $inventory)
    {
        if ($inventory->isKelengkapan() && $inventory->parent_id) {
            return response()->json([
                'message' => 'Kelengkapan ini menempel ke barang utama dan tidak bisa dipinjam sendirian. Pinjamkan barang utamanya -- kelengkapan yang tersedia akan otomatis ikut dipinjamkan.',
            ], 422);
        }

        if ($inventory->status !== 'tersedia') {
            return response()->json(['message' => 'Item ini sedang tidak tersedia untuk diserahkan.'], 422);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'tanggal_penerimaan' => 'required|date',
            'catatan_penerimaan' => 'nullable|string',
            'foto_penerimaan' => 'required|array|min:1|max:3',
            'foto_penerimaan.*' => 'image|mimes:jpg,jpeg,png,webp|max:1024',
        ], [
            'foto_penerimaan.*.max' => 'Maksimal size foto adalah 1MB',
        ]);

        $pemakai = DB::transaction(function () use ($inventory, $request, $validated) {
            $noStruk = $this->generateNoStruk('STJ', 'inventory_pemakai', 'no_struk_penerimaan');

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_penerimaan', 'inventory-pemakai/penerimaan');

            $pemakai = InventoryPemakai::create([
                'inventory_id' => $inventory->id,
                'user_id' => $validated['user_id'],
                'status' => 'disetujui',
                'requested_by_user_id' => $request->user()?->id,
                'no_struk_penerimaan' => $noStruk,
                'tanggal_penerimaan' => $validated['tanggal_penerimaan'],
                'diterima_at' => now(),
                'catatan_penerimaan' => $validated['catatan_penerimaan'] ?? null,
                'foto_penerimaan' => $fotoPaths,
            ]);

            $inventory->update(['status' => 'dipakai']);

            // Kelengkapan yang nempel (children) ikut aset induknya --
            // begitu induk dipinjamkan, semua kelengkapan miliknya yang
            // masih 'tersedia' otomatis ikut dipinjamkan ke pemakai yang
            // sama, pakai foto bukti & tanggal serah-terima yang sama juga
            // (satu kejadian serah-terima fisik, bukan kejadian
            // terpisah-pisah). Kalau $inventory ini sendiri Kelengkapan
            // berdiri sendiri, children() bakal selalu kosong (kelengkapan
            // gak boleh punya anak, ditegakkan di InventoryController), jadi
            // aman dipanggil tanpa pengecekan isBarangUtama() tambahan.
            $inventory->children()
                ->where('status', 'tersedia')
                ->get()
                ->each(function ($anak) use ($validated, $fotoPaths, $request) {
                    $noStrukAnak = $this->generateNoStruk('STJ', 'inventory_pemakai', 'no_struk_penerimaan');

                    InventoryPemakai::create([
                        'inventory_id' => $anak->id,
                        'user_id' => $validated['user_id'],
                        'status' => 'disetujui',
                        'requested_by_user_id' => $request->user()?->id,
                        'no_struk_penerimaan' => $noStrukAnak,
                        'tanggal_penerimaan' => $validated['tanggal_penerimaan'],
                        'diterima_at' => now(),
                        'catatan_penerimaan' => 'Dipinjamkan otomatis -- mengikuti barang utama.',
                        'foto_penerimaan' => $fotoPaths,
                    ]);

                    $anak->update(['status' => 'dipakai']);
                });

            return $pemakai;
        });

        return response()->json($pemakai->load('user', 'inventory'), 201);
    }

    /**
     * POST /api/inventory-pemakai/{inventoryPemakai}/kembalikan
     * Admin terima kembali item dari pemakai, ATAU pemakainya sendiri
     * (karyawan/cabang yang lagi pegang item ini) yang ngembaliin langsung.
     * Wajib sertain no_struk_penerimaan (struk asli pas serah-terima)
     * sebagai bukti pengembalian ini benar. Ditolak kalau masih ada laporan
     * penanganan/perbaikan yang belum selesai (guard ini secara alami cuma
     * pernah kena buat Barang Utama, karena Kelengkapan gak lewat alur
     * InventoryPenanganan).
     *
     * Wajib sertakan foto_pengembalian (array file, 1-3 foto) sebagai bukti
     * kondisi fisik item saat dikembalikan.
     */
    public function kembalikan(Request $request, InventoryPemakai $inventoryPemakai)
    {
        $user = $request->user();
        $isPemilikPemakaian = $inventoryPemakai->user_id === $user->id;

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

        if ($inventoryPemakai->status !== 'disetujui') {
            throw ValidationException::withMessages([
                'status' => ['Data pemakaian ini bukan pemakaian aktif yang bisa dikembalikan.'],
            ]);
        }

        if ($validated['no_struk_penerimaan'] !== $inventoryPemakai->no_struk_penerimaan) {
            throw ValidationException::withMessages([
                'no_struk_penerimaan' => ['Nomor struk penerimaan tidak cocok. Pengembalian wajib menyertakan bukti serah-terima yang benar.'],
            ]);
        }

        $adaPenangananBelumSelesai = InventoryPenanganan::where('inventory_pemakai_id', $inventoryPemakai->id)
            ->whereNull('tanggal_selesai')
            ->exists();

        if ($adaPenangananBelumSelesai) {
            throw ValidationException::withMessages([
                'penanganan' => ['Masih ada laporan penanganan/perbaikan yang belum diselesaikan untuk pemakaian ini.'],
            ]);
        }

        DB::transaction(function () use ($inventoryPemakai, $request, $validated) {
            $noStruk = $this->generateNoStruk('KBL', 'inventory_pemakai', 'no_struk_pengembalian');

            // 'rusak_berat' cuma dikenal di status Barang Utama -- Kelengkapan
            // cuma punya tersedia/dipakai/rusak, jadi gak ada state permanen
            // yang perlu di-guard buat Kelengkapan.
            $catatanDefault = $inventoryPemakai->catatan_pengembalian;
            if ($inventoryPemakai->inventory?->status === 'rusak_berat') {
                $catatanDefault = 'Dikembalikan dalam kondisi rusak berat (tidak bisa diperbaiki).';
            }

            $fotoPaths = $this->simpanFotoBukti($request, 'foto_pengembalian', 'inventory-pemakai/pengembalian');

            $inventoryPemakai->update([
                'no_struk_pengembalian' => $noStruk,
                'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                'dikembalikan_at' => now(),
                'catatan_pengembalian' => $validated['catatan_pengembalian'] ?? $catatanDefault,
                'foto_pengembalian' => $fotoPaths,
            ]);

            // jangan paksa balik 'tersedia' kalau lagi rusak_berat -- dia
            // tetep gak boleh dipinjemin lagi walau pemakaiannya udah ditutup.
            // Kelengkapan gak punya state itu, jadi selalu balik normal ke
            // 'tersedia'.
            $inventoryPemakai->inventory()
                ->where('status', '!=', 'rusak_berat')
                ->update(['status' => 'tersedia']);

            // Kelengkapan yang nempel (children dari item yang dikembalikan
            // ini) ikut balik juga pas dikembalikan -- semua yang masih aktif
            // dipinjam (disetujui, belum ada tanggal_pengembalian) ditutup
            // bareng, pakai foto & tanggal pengembalian yang sama dengan
            // induknya (satu kejadian serah-terima fisik). Kalau item ini
            // Kelengkapan berdiri sendiri, query di bawah otomatis gak nemu
            // apa-apa (gak ada baris lain yang parent_id-nya nunjuk ke dia).
            $noStrukIndukKembali = $inventoryPemakai->no_struk_pengembalian;
            InventoryPemakai::whereIn('inventory_id', function ($q) use ($inventoryPemakai) {
                $q->select('id')
                    ->from('inventory')
                    ->where('parent_id', $inventoryPemakai->inventory_id);
            })
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->get()
                ->each(function ($anakPemakai) use ($validated, $fotoPaths, $noStrukIndukKembali) {
                    $noStrukAnak = $this->generateNoStruk('KBL', 'inventory_pemakai', 'no_struk_pengembalian');

                    $anakPemakai->update([
                        'no_struk_pengembalian' => $noStrukAnak,
                        'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                        'dikembalikan_at' => now(),
                        'catatan_pengembalian' => 'Dikembalikan otomatis -- mengikuti barang utama (' . $noStrukIndukKembali . ').',
                        'foto_pengembalian' => $fotoPaths,
                    ]);

                    $anakPemakai->inventory()->update(['status' => 'tersedia']);
                });
        });

        return response()->json($inventoryPemakai->fresh()->load('user', 'inventory'));
    }

    /**
     * DELETE /api/inventory-pemakai/{inventoryPemakai}
     * Admin: hapus satu entri riwayat pemakaian (Barang Utama maupun
     * Kelengkapan). Ditolak kalau entri ini punya laporan
     * penanganan/perbaikan yang nempel (inventory_pemakai_id) -- guard ini
     * secara alami cuma pernah kena buat Barang Utama, karena Kelengkapan
     * gak lewat alur InventoryPenanganan. Kalau entri yang dihapus ini
     * pemakaian yang masih aktif (belum dikembalikan), status item-nya
     * dikembalikan ke 'tersedia' dulu (kecuali lagi rusak_berat -- tetap
     * gak boleh dipinjemin lagi).
     */
    public function destroy(InventoryPemakai $inventoryPemakai)
    {
        if (InventoryPenanganan::where('inventory_pemakai_id', $inventoryPemakai->id)->exists()) {
            return response()->json([
                'message' => 'Riwayat pemakaian ini punya laporan perbaikan terkait dan tidak bisa dihapus.',
            ], 422);
        }

        $masihDipakai = $inventoryPemakai->status === 'disetujui' && !$inventoryPemakai->tanggal_pengembalian;

        DB::transaction(function () use ($inventoryPemakai, $masihDipakai) {
            if ($masihDipakai) {
                $inventoryPemakai->inventory()
                    ->where('status', '!=', 'rusak_berat')
                    ->update(['status' => 'tersedia']);

                // Sama seperti kembalikan(): entri kelengkapan yang masih
                // aktif ikut item ini juga ikut dilepas & ditutup statusnya
                // (bukan dihapus, biar riwayatnya tetap ada), biar gak
                // nyangkut "dipakai" padahal riwayat pinjam induknya udah
                // dihapus.
                InventoryPemakai::whereIn('inventory_id', function ($q) use ($inventoryPemakai) {
                    $q->select('id')
                        ->from('inventory')
                        ->where('parent_id', $inventoryPemakai->inventory_id);
                })
                    ->where('status', 'disetujui')
                    ->whereNull('tanggal_pengembalian')
                    ->get()
                    ->each(function ($anakPemakai) {
                        $anakPemakai->update([
                            'tanggal_pengembalian' => now()->toDateString(),
                            'dikembalikan_at' => now(),
                            'catatan_pengembalian' => 'Dikembalikan otomatis -- riwayat pinjam barang utama dihapus.',
                        ]);
                        $anakPemakai->inventory()->update(['status' => 'tersedia']);
                    });
            }
            $inventoryPemakai->delete();
        });

        return response()->json(['message' => 'Riwayat pemakaian berhasil dihapus.']);
    }
}