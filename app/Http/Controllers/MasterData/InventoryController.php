<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\MasterData\Inventory;
use App\Models\MasterData\Kategori;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryWriteoff;
use App\Models\User;
use App\Notifications\AsetKelengkapanKerusakanDilaporkan;
use App\Notifications\KelengkapanDilepasDariInduk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class InventoryController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * GET /api/inventory
     * Admin: daftar SEMUA inventory (perilaku lama AsetController@index,
     * gak berubah).
     * Non-admin (karyawan/cabang/manajer/hr): dibatasi cuma yang statusnya
     * 'tersedia' (biar tau apa yang bisa dipinjam) PLUS yang LAGI dia
     * pegang/pending sendiri (pemakaiSaatIni/pemakaiPending -- bukan
     * `pemakai()` yang isinya riwayat lengkap, biar item yang PERNAH dia
     * pegang tapi sekarang udah 'rusak_berat'/'dijual'/'rusak' gak ikut
     * kebocor). Status-status itu juga sengaja DIKECUALIKAN total dari
     * non-admin, bukan cuma disembunyiin di tab React.
     *
     * Filter opsional:
     * - ?kategori=barang_utama|kelengkapan -- filter berdasar Kategori
     *   (lewat kategori.nama), BUKAN ada/tidaknya parent_id.
     * - ?parent_id=123 -- kelengkapan yang nempel ke barang utama tertentu
     *   (dipakai buat expand/nested view di tabel).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        $query = Inventory::with([
            'kategori',
            'departemen',
            'lokasiKantor',
            'supplier',
            'parent:id,kode_inventory,nama',
            'pemakaiSaatIni.user.departemen',
            'pemakaiPending.user.departemen',
            'penangananAktif',
            'writeoff.penyetuju:id,name',
        ])->latest();

        if ($kategori = $request->query('kategori')) {
            abort_unless(in_array($kategori, ['barang_utama', 'kelengkapan'], true), 422, 'Kategori tidak valid.');
            $kategori === 'barang_utama' ? $query->barangUtama() : $query->kelengkapan();
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->query('parent_id'));
        }

        if (!$isAdmin) {
            $query->whereNotIn('status', ['rusak_berat', 'dijual', 'rusak'])
                ->where(function ($q) use ($user) {
                    $q->where('status', 'tersedia')
                        ->orWhereHas('pemakaiSaatIni', function ($sub) use ($user) {
                            $sub->where('user_id', $user->id);
                        })
                        ->orWhereHas('pemakaiPending', function ($sub) use ($user) {
                            $sub->where('user_id', $user->id);
                        });
                });
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/inventory/{inventory}
     * Detail satu item, termasuk riwayat lengkap (pemakai & penanganan) buat
     * halaman detail, plus kelengkapan anaknya (children) kalau ini barang
     * utama. Non-admin cuma boleh buka detail item yang 'tersedia' atau yang
     * LAGI dia pegang/pending sendiri -- sama scoping-nya kayak index(). Ini
     * WAJIB dicek di sini juga (bukan cuma index()), soalnya endpoint ini
     * bisa dipanggil langsung lewat ID tanpa lewat daftar/tabel.
     */
    public function show(Request $request, Inventory $inventory)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        if (!$isAdmin) {
            abort_if(in_array($inventory->status, ['rusak_berat', 'dijual', 'rusak'], true), 403, 'Kamu tidak punya akses untuk melihat detail item ini.');

            $terkaitUser = $inventory->pemakaiSaatIni()->where('user_id', $user->id)->exists()
                || $inventory->pemakaiPending()->where('user_id', $user->id)->exists();

            abort_unless($inventory->status === 'tersedia' || $terkaitUser, 403, 'Kamu tidak punya akses untuk melihat detail item ini.');
        }

        $inventory->load([
            'kategori',
            'departemen',
            'lokasiKantor',
            'supplier',
            'parent',
            'children.supplier',
            'pemakaiSaatIni.user.departemen',
            'pemakaiPending.user.departemen',
            'pemakai.user.departemen',
            'penanganan',
            'penangananAktif',
            'writeoff.penyetuju:id,name',
        ]);

        return response()->json($inventory);
    }

    /**
     * POST /api/inventory
     * kode_inventory digenerate otomatis lewat trigger DB, gak perlu (&
     * gak boleh) dikirim dari frontend.
     */
    public function store(Request $request)
    {
        $validated = $this->validasi($request);

        $inventory = DB::transaction(function () use ($request, $validated) {
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('inventory', 'public');
            }

            return Inventory::create($validated);
        });

        return response()->json(
            $inventory->load('kategori', 'departemen', 'lokasiKantor', 'supplier', 'parent'),
            201
        );
    }

    /**
     * POST /api/inventory/{inventory} (+ _method=PUT dari frontend, krn ada
     * file upload)
     * Foto lama dihapus dari storage kalau diganti foto baru.
     *
     * BARU: kalau lewat form ini sebuah Kelengkapan baru di-attach ke induk
     * (parent_id berubah dari kosong/beda jadi terisi) dan induknya
     * kebetulan lagi berstatus 'dipakai' (ada pemakaian aktif), kelengkapan
     * ini otomatis ikut disetel 'dipakai' + dibuatkan riwayat
     * InventoryPemakai -- sinkron dengan logic yang sama di
     * pasangPenggantiKelengkapan(), supaya kedua jalur attach (form Edit
     * biasa maupun endpoint khusus pasang-pengganti) konsisten. Tanpa ini,
     * kelengkapan yang di-attach lewat form Edit akan nyangkut di status
     * 'tersedia' walau induknya udah dipinjam orang.
     */
    public function update(Request $request, Inventory $inventory)
    {
        $validated = $this->validasi($request, $inventory);

        DB::transaction(function () use ($request, $validated, $inventory) {
            if ($request->hasFile('foto')) {
                if ($inventory->foto) {
                    Storage::disk('public')->delete($inventory->foto);
                }
                $validated['foto'] = $request->file('foto')->store('inventory', 'public');
            }

            // Tangkep parent_id LAMA sebelum update() -- dipakai buat
            // bedain "baru di-attach sekarang" vs "form disubmit ulang
            // dengan parent_id yang sama seperti sebelumnya" (misal admin
            // cuma ubah field lain, parent_id-nya gak berubah). Tanpa
            // pembanding ini, tiap submit form Edit akan selalu bikin
            // record InventoryPemakai baru berulang-ulang.
            $parentIdLama = $inventory->parent_id;

            $inventory->update($validated);

            $parentBaruDiattach = $inventory->isKelengkapan()
                && $inventory->parent_id
                && $inventory->parent_id != $parentIdLama;

            if ($parentBaruDiattach) {
                $this->ikutkanKelengkapanKePemakaianInduk($inventory);
            }
        });

        return response()->json(
            $inventory->fresh()->load('kategori', 'departemen', 'lokasiKantor', 'supplier', 'parent')
        );
    }

    /**
     * DELETE /api/inventory/{inventory}
     * ?force=1 lewatin guard riwayat — dipakai admin buat bersihin data
     * lama/test yang gak bisa kehapus normal krn udah punya riwayat
     * pemakai/penanganan/kelengkapan anak. Aman: inventory_pemakai &
     * inventory_penanganan semua cascadeOnDelete di FK-nya, jadi riwayat
     * ikut kehapus bersih, gak nyisa orphan row.
     */
    public function destroy(Request $request, Inventory $inventory)
    {
        $force = $request->boolean('force');

        if (!$force) {
            if ($inventory->children()->exists()) {
                return response()->json([
                    'message' => 'Item ini masih punya kelengkapan yang menempel. Lepas dulu kelengkapannya sebelum menghapus.',
                    'force_available' => true,
                ], 422);
            }

            if ($inventory->penanganan()->exists()) {
                return response()->json([
                    'message' => 'Item ini punya riwayat perbaikan/penanganan dan tidak bisa dihapus.',
                    'force_available' => true,
                ], 422);
            }

            if ($inventory->pemakai()->exists()) {
                return response()->json([
                    'message' => 'Item ini punya riwayat peminjaman dan tidak bisa dihapus.',
                    'force_available' => true,
                ], 422);
            }
        } else {
            // force=1 lepas dulu kelengkapan anak (jadi berdiri sendiri, bukan
            // ikut kehapus) sebelum baris induknya dihapus -- biar gak ada FK
            // yang mental atau anak yang ikut lenyap padahal masih valid.
            $inventory->children()->update(['parent_id' => null]);
        }

        $kodeInventory = $inventory->kode_inventory;
        $inventory->delete();

        return response()->json(['message' => "Inventory {$kodeInventory} berhasil dihapus."]);
    }

    /**
     * POST /api/inventory/{inventory}/jual
     * Cuma berlaku buat Barang Utama (kelengkapan gak lewat alur writeoff --
     * kalau kelengkapan rusak permanen, cukup dibiarkan status 'rusak' lewat
     * laporRusakKelengkapan()).
     */
    public function jual(Request $request, Inventory $inventory)
    {
        abort_unless($inventory->isBarangUtama(), 422, 'Hanya Barang Utama yang bisa dijual/writeoff.');

        abort_if(
            $inventory->children()->exists(),
            422,
            'Item ini masih punya kelengkapan yang menempel. Lepas dulu kelengkapannya sebelum menjual/writeoff item ini.'
        );

        abort_if(
            $inventory->parent_id,
            422,
            'Item ini masih menempel ke induk. Lepas dulu dari induknya sebelum menjual/writeoff item ini.'
        );

        if ($inventory->status !== 'rusak_berat' && $inventory->status !== 'tersedia') {
            return response()->json([
                'message' => 'Item hanya bisa dijual jika statusnya Rusak Berat.',
            ], 422);
        }

        $validated = $request->validate([
            'alasan' => 'nullable|string',
            'no_berita_acara' => 'nullable|string|max:255',
            'catatan' => 'nullable|string',
        ]);

        DB::transaction(function () use ($inventory, $request, $validated) {
            $inventory->update(['status' => 'dijual']);

            // jaga-jaga: kalau masih ada record inventory_pemakai yang
            // "nyangkut" aktif (data lama sebelum ada auto-kembalikan di
            // rusak_berat), tutup paksa di sini juga biar "Dipakai Oleh" gak
            // nunjuk ke orang yang udah gak pegang item ini lagi.
            InventoryPemakai::where('inventory_id', $inventory->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => now(),
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — barang dijual.',
                ]);

            // catat sebagai riwayat writeoff biar muncul akurat di panel
            // "Riwayat Inventory" (siapa yang nyetujui, kapan, kenapa) --
            // bukan cuma ganti status tanpa jejak.
            InventoryWriteoff::updateOrCreate(
                ['inventory_id' => $inventory->id],
                [
                    'disetujui_oleh' => $request->user()?->id,
                    'alasan' => $validated['alasan'] ?? 'Rusak berat, tidak dapat diperbaiki lagi.',
                    'no_berita_acara' => $validated['no_berita_acara'] ?? null,
                    'tanggal_writeoff' => now()->toDateString(),
                    'catatan' => $validated['catatan'] ?? null,
                ]
            );
        });

        return response()->json($inventory->fresh()->load([
            'kategori',
            'departemen',
            'supplier',
            'pemakaiSaatIni',
            'writeoff.penyetuju:id,name',
        ]));
    }

    /**
     * POST /api/inventory/{inventory}/lapor-rusak-kelengkapan
     * eks AsetKelengkapanController@laporRusak.
     *
     * LEGACY -- sudah TIDAK dipanggil dari frontend lagi. Kelengkapan yang
     * nempel ke induk sekarang lapor kerusakan lewat endpoint yang sama
     * kaya Barang Utama (InventoryPenanganan::store(), lihat komentar di
     * sana), biar bisa lewat proses "diperbaiki" dulu -- bukan langsung
     * final kaya endpoint ini. Endpoint ini dibiarkan hidup (gak dihapus)
     * buat jaga-jaga ada integrasi lama yang masih manggil, tapi jangan
     * dipakai buat fitur baru. Aman dihapus kalau nanti dipastikan gak ada
     * pemanggil lain selain frontend yang sudah diubah ini.
     */
    public function laporRusakKelengkapan(Inventory $inventory)
    {
        abort_unless($inventory->isKelengkapan(), 422, 'Hanya Kelengkapan yang bisa dilaporkan lewat endpoint ini.');
        abort_unless($inventory->parent_id, 422, 'Kelengkapan ini berdiri sendiri -- gunakan alur lapor kerusakan yang sama seperti Barang Utama, bukan endpoint ini.');

        if ($inventory->status === 'rusak') {
            return response()->json([
                'message' => 'Kelengkapan ini sudah dilaporkan rusak.',
            ], 422);
        }

        // tangkep dulu sebelum parent_id dikosongin di transaksi bawah --
        // butuh buat isi pesan notif ("terpasang di ...").
        $parentLabel = null;
        if ($inventory->parent_id) {
            $parent = $inventory->load('parent')->parent;
            if ($parent) {
                $parentLabel = trim(($parent->kode_inventory ?? '') . ' ' . ($parent->nama ?? ''));
            }
        }

        DB::transaction(function () use ($inventory) {
            $inventory->update([
                'parent_id' => null,
                'status' => 'rusak',
                'tanggal_rusak' => now(),
            ]);

            InventoryPemakai::where('inventory_id', $inventory->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => now(),
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — kelengkapan dinyatakan rusak.',
                ]);
        });

        // notif ke manajer/hr/admin tiap ada laporan kerusakan kelengkapan
        // masuk (database + broadcast + web push), sama pola kayak laporan
        // kerusakan barang utama. Yang lapor selalu admin (route ini
        // role:admin-only) jadi dia sendiri dikecualikan dari penerima biar
        // gak notif diri sendiri.
        // try-catch: laporan yang SUDAH tersimpan di atas jangan ikut gagal
        // kalau notif error.
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])
                    ->where('id', '!=', auth()->id())
                    ->get(),
                new AsetKelengkapanKerusakanDilaporkan($inventory, $parentLabel, auth()->user()->name)
            );
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi laporan kerusakan kelengkapan', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(
            $inventory->fresh()->load(['parent', 'lokasiKantor', 'supplier'])
        );
    }

    /**
     * POST /api/inventory/{inventory}/pasang-pengganti-kelengkapan
     * eks AsetKelengkapanController@pasangPengganti. Nempelin kelengkapan
     * yang 'tersedia' ke barang utama tertentu.
     *
     * BARU: sekarang pakai helper ikutkanKelengkapanKePemakaianInduk() yang
     * sama dengan update() -- selain nyetel status 'dipakai', juga bikin
     * record InventoryPemakai buat kelengkapan ini (dulu cuma ganti status
     * doang, gak ada riwayat pemakai yang tercatat, dan gak akan ke-cover
     * pas induknya dikembalikan lewat InventoryPemakaiController::kembalikan()
     * yang query-nya berdasarkan tabel inventory_pemakai).
     */
    public function pasangPenggantiKelengkapan(Request $request, Inventory $inventory)
    {
        abort_unless($inventory->isKelengkapan(), 422, 'Hanya Kelengkapan yang bisa dipasang lewat endpoint ini.');

        if ($inventory->status !== 'tersedia') {
            return response()->json([
                'message' => 'Kelengkapan ini tidak tersedia untuk dipasang.',
            ], 422);
        }

        $validated = $request->validate([
            'parent_id' => 'required|exists:inventory,id',
        ]);

        $parent = Inventory::with('kategori')->findOrFail($validated['parent_id']);
        abort_unless($parent->isBarangUtama(), 422, 'parent_id harus menunjuk ke Barang Utama.');

        DB::transaction(function () use ($inventory, $parent) {
            $inventory->update(['parent_id' => $parent->id]);

            $this->ikutkanKelengkapanKePemakaianInduk($inventory);
        });

        return response()->json(
            $inventory->fresh()->load(['parent', 'lokasiKantor', 'supplier'])
        );
    }

    /**
     * Helper bareng buat update() & pasangPenggantiKelengkapan(): kalau
     * induk dari $inventory (Kelengkapan yang baru di-attach) lagi punya
     * pemakaian aktif (status 'disetujui', belum dikembalikan), buatkan
     * $inventory ini record InventoryPemakai yang senasib (user_id, foto
     * penerimaan sama seperti induknya) dan setel statusnya 'dipakai'.
     * Kalau induknya lagi 'tersedia' (gak ada pemakaian aktif),
     * $inventory dibiarkan apa adanya -- gak ada perubahan.
     *
     * Dipanggil di DALAM DB::transaction masing-masing caller.
     */
    protected function ikutkanKelengkapanKePemakaianInduk(Inventory $inventory): void
    {
        $pemakaiAktifInduk = InventoryPemakai::where('inventory_id', $inventory->parent_id)
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->latest('tanggal_penerimaan')
            ->first();

        if (!$pemakaiAktifInduk) {
            return;
        }

        $noStrukAnak = $this->generateNoStruk('STJ', 'inventory_pemakai', 'no_struk_penerimaan');

        InventoryPemakai::create([
            'inventory_id' => $inventory->id,
            'user_id' => $pemakaiAktifInduk->user_id,
            'status' => 'disetujui',
            'no_struk_penerimaan' => $noStrukAnak,
            'tanggal_penerimaan' => now()->toDateString(),
            'diterima_at' => now(),
            'catatan_penerimaan' => 'Ditambahkan & dipinjamkan otomatis -- menempel ke barang utama yang sedang dipakai.',
            'foto_penerimaan' => $pemakaiAktifInduk->foto_penerimaan,
        ]);

        $inventory->update(['status' => 'dipakai']);
    }

    /**
     * POST /api/inventory/{inventory}/lepas-dari-induk
     * Satu-satunya jalan buat ngosongin parent_id Kelengkapan yang lagi
     * nempel -- manual oleh admin, TIDAK ada proses otomatis lain (termasuk
     * lapor rusak / hasil penanganan rusak_berat) yang boleh ngelakuin ini.
     *
     * Endpoint ini MURNI mutusin hubungan parent_id -- status TIDAK disentuh
     * sama sekali. Status kelengkapan udah sepenuhnya dipegang event lain:
     * InventoryPemakai (dipakai/tersedia), InventoryPenanganan
     * (rusak/menunggu_perbaikan/diperbaiki/rusak_berak, termasuk buat
     * kelengkapan yang masih nempel -- lihat komentar di
     * InventoryPenangananController@update), atau jual() (dijual). Apa pun
     * statusnya sekarang, itu yang kebawa apa adanya pas dilepas -- gak ada
     * pilihan status baru di sini, biar gak ada celah nimpa status yang udah
     * resmi tercatat lewat proses lain.
     */
    public function lepasDariInduk(Request $request, Inventory $inventory)
    {
        abort_unless($inventory->isKelengkapan(), 422, 'Hanya Kelengkapan yang bisa dilepas dari induk.');
        abort_unless($inventory->parent_id, 422, 'Kelengkapan ini tidak sedang menempel ke induk manapun.');

        $validated = $request->validate([
            'keterangan' => 'nullable|string',
        ]);

        // tangkep dulu sebelum parent_id dikosongin di transaksi bawah --
        // butuh buat isi pesan notif ("sebelumnya terpasang di ...").
        $indukLabel = null;
        $parent = $inventory->load('parent')->parent;
        if ($parent) {
            $indukLabel = trim(($parent->kode_inventory ?? '') . ' ' . ($parent->nama ?? ''));
        }

        DB::transaction(function () use ($inventory) {
            $inventory->update([
                'parent_id' => null,
            ]);

            InventoryPemakai::where('inventory_id', $inventory->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => now(),
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — kelengkapan dilepas dari induk oleh admin.',
                ]);
        });

        // notif ke manajer/hr/admin, exclude admin yang ngelakuin aksi ini
        // sendiri. try-catch: aksi lepas yang SUDAH tersimpan di atas jangan
        // ikut gagal kalau notif error.
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])
                    ->where('id', '!=', auth()->id())
                    ->get(),
                new KelengkapanDilepasDariInduk(
                    $inventory,
                    $indukLabel,
                    $inventory->status,
                    $validated['keterangan'] ?? null,
                    auth()->user()->name
                )
            );
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi lepas kelengkapan dari induk', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(
            $inventory->fresh()->load(['kategori', 'lokasiKantor', 'supplier'])
        );
    }

    /**
     * GET /api/inventory/kelengkapan/rusak
     * eks AsetKelengkapanController@rusak -- daftar kelengkapan berstatus
     * 'rusak', paginated & bisa dicari. Dipakai tab "Rusak" di halaman Foto
     * Inventory / laporan kelengkapan.
     */
    public function rusakKelengkapan(Request $request)
    {
        $query = Inventory::query()
            ->kelengkapan()
            ->where('status', 'rusak');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_inventory', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%");
            });
        }

        $data = $query
            ->with(['parent', 'lokasiKantor', 'supplier'])
            ->orderByDesc('tanggal_rusak')
            ->paginate($request->query('per_page', 15));

        return response()->json($data);
    }

    /**
     * Validasi bersama buat store() & update(). Field unique
     * (serial_number) dikecualikan dari record itu sendiri kalau lagi mode
     * update. Multipart FormData ngirim '' (bukan field ilang) pas
     * dikosongkan dari FE, jadi field nullable dinormalisasi manual ke null
     * biar exists:... gak ikut divalidasi & biar update() beneran
     * ngosongin kolomnya (bukan malah dibiarin ke value lama krn key gak
     * ke-set).
     */
    protected function validasi(Request $request, ?Inventory $inventory = null): array
    {
        $request->merge([
            'serial_number' => $request->serial_number === '' ? null : $request->serial_number,
            'parent_id' => $request->parent_id === '' ? null : $request->parent_id,
            'kategori_id' => $request->kategori_id === '' ? null : $request->kategori_id,
            'departemen_id' => $request->departemen_id === '' ? null : $request->departemen_id,
            'lokasi_kantor_id' => $request->lokasi_kantor_id === '' ? null : $request->lokasi_kantor_id,
        ]);

        $validated = $request->validate([
            'parent_id' => 'nullable|exists:inventory,id',
            'kategori_id' => 'required|exists:kategori,id',
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'nama' => 'nullable|string|max:255',
            'warna' => 'nullable|string|max:255',
            'serial_number' => [
                'nullable',
                'string',
                'max:255',
                $inventory
                    ? 'unique:inventory,serial_number,' . $inventory->id
                    : 'unique:inventory,serial_number',
            ],
            'jumlah' => 'nullable|integer|min:1',
            'tanggal_garansi' => 'nullable|date',
            'perusahaan' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|image|max:4096',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string|max:255',
            'no_good_receive' => 'nullable|string|max:255',
            'status' => 'nullable|in:tersedia,dipakai,rusak,menunggu_perbaikan,diperbaiki,rusak_berat',
            'tanggal_rusak' => 'nullable|date',
        ]);

        $this->validasiParent($validated, $inventory);

        return $validated;
    }

    /**
     * Constraint dari dokumen migrasi #2.3: parent_id cuma boleh diisi
     * kalau (a) baris yang lagi dibuat/diedit ini kategorinya Kelengkapan,
     * dan (b) baris target parent_id-nya kategorinya Barang Utama. Barang
     * Utama gak boleh nempel ke apapun, Kelengkapan gak boleh nempel ke
     * Kelengkapan lain.
     */
    protected function validasiParent(array $validated, ?Inventory $inventory): void
    {
        if (empty($validated['parent_id'])) {
            return;
        }

        $kategoriId = array_key_exists('kategori_id', $validated)
            ? $validated['kategori_id']
            : $inventory?->kategori_id;

        $kategoriSelf = $kategoriId ? Kategori::find($kategoriId) : null;

        abort_if($kategoriSelf?->isBarangUtama(), 422, 'Barang Utama tidak boleh menempel ke item lain (parent_id harus kosong).');

        $parent = Inventory::with('kategori')->find($validated['parent_id']);
        abort_unless($parent && $parent->isBarangUtama(), 422, 'parent_id harus menunjuk ke Barang Utama.');
    }
}