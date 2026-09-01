<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Http\Controllers\Concerns\SimpanFotoBukti;
use App\Models\MasterData\Inventory;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryWriteoff;
use App\Models\User;
use App\Notifications\KelengkapanDilepasDariInduk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class InventoryController extends Controller
{
    use GeneratesStrukNumber;
    use SimpanFotoBukti;
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
     * - ?kategori_id=5 -- filter berdasar kategori beneran (Laptop,
     *   Charger, dst -- lihat GET /api/kategori), BUKAN lagi tipe
     *   barang_utama/kelengkapan (konsep itu sudah tidak ada).
     * - ?posisi=induk|menempel -- filter berdasar STRUKTUR (parent_id),
     *   independen dari kategori: 'induk' = item berdiri sendiri/belum
     *   menempel ke item lain (parent_id kosong -- dipakai juga buat
     *   ambil daftar calon induk di form "Pasang ke Induk"), 'menempel' =
     *   item yang lagi menempel ke item lain (parent_id terisi).
     * - ?parent_id=123 -- item yang menempel ke item tertentu (dipakai
     *   buat expand/nested view di tabel).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        $query = Inventory::with([
            'kategori',
            'supplier',
            'parent:id,kode_inventory,nama',
            'pemakaiSaatIni.user.departemen',
            'pemakaiPending.user.departemen',
            'penangananAktif',
            'writeoff.penyetuju:id,name',
        ])->latest();

        if ($kategoriId = $request->query('kategori_id')) {
            $query->where('kategori_id', $kategoriId);
        }

        if ($posisi = $request->query('posisi')) {
            abort_unless(in_array($posisi, ['induk', 'menempel'], true), 422, 'Posisi tidak valid.');
            $posisi === 'induk' ? $query->indukSendiri() : $query->menempel();
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
            $inventory->load('kategori', 'supplier', 'parent'),
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
     *
     * CATATAN: status akhir kelengkapan ('dipakai'/'tersedia') sendiri
     * sekarang selalu diselaraskan otomatis dari ada/tidaknya parent_id
     * lewat selaraskanStatusByParent() di dalam validasi() -- terlepas
     * dari apakah induknya lagi ada pemakaian aktif atau tidak. Blok
     * ikutkanKelengkapanKePemakaianInduk()/lepasKelengkapanDariPemakaianAktif()
     * di bawah ini TETAP dijalankan, tapi sekarang cuma tanggung jawab buat
     * catatan riwayat InventoryPemakai (siapa yang "pegang" kelengkapan ini
     * ikut induknya) -- bukan lagi yang nentuin status.
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
            // bedain "baru di-attach sekarang" / "baru dilepas sekarang"
            // vs "form disubmit ulang dengan parent_id yang sama seperti
            // sebelumnya" (misal admin cuma ubah field lain, parent_id-nya
            // gak berubah). Tanpa pembanding ini, tiap submit form Edit
            // akan selalu bikin/nutup record InventoryPemakai berulang-ulang.
            //
            // REVISI: dulu ada gate tambahan "item ini kategori Kelengkapan?"
            // sebelum cek transisi parent_id -- gate itu sekarang dihapus
            // karena sekarang SEMUA item bisa jadi anak/induk, gak cuma yang
            // dulu berkategori Kelengkapan (dulu gate itu sebenarnya selalu
            // redundan buat Barang Utama juga, karena Barang Utama memang gak
            // pernah bisa punya parent_id -- jadi perilaku buat data lama
            // tetap identik, cuma sekarang generalisasinya benar buat item
            // kategori apapun).
            $parentIdLama = $inventory->parent_id;

            $inventory->update($validated);

            $parentBaruDiattach = $inventory->parent_id
                && $inventory->parent_id != $parentIdLama;

            $parentBaruDilepas = $parentIdLama
                && !$inventory->parent_id;

            if ($parentBaruDiattach) {
                $this->ikutkanKelengkapanKePemakaianInduk($inventory);
            }

            // BARU: simetris dengan attach di atas -- kalau parent_id
            // dilepas lewat form Edit ini (bukan lewat tombol khusus
            // "Lepas dari Induk"/lepasDariInduk()), kelengkapan ini juga
            // harus ikut ditutup pemakaian aktifnya. (Status sendiri udah
            // dipaksa balik 'tersedia' lebih awal lewat
            // selaraskanStatusByParent() di validasi() -- baris ini
            // sekarang murni beres-beres riwayat InventoryPemakai.) Tanpa
            // ini, riwayat pemakaian kelengkapan yang tadinya ikut induknya
            // bakal nyangkut "aktif" (tanggal_pengembalian kosong)
            // selamanya walau parent_id-nya udah kosong & statusnya udah
            // 'tersedia'.
            if ($parentBaruDilepas) {
                $this->lepasKelengkapanDariPemakaianAktif($inventory, 'Dikembalikan otomatis — kelengkapan dilepas dari induk lewat form edit.');
            }
        });

        return response()->json(
            $inventory->fresh()->load('kategori', 'supplier', 'parent')
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
            //
            // BARU: query builder di sini gak lewat validasi()/update() model
            // Eloquent, jadi selaraskanStatusByParent() gak ikut kepanggil
            // otomatis -- status HARUS disamakan manual di sini juga, biar
            // invariant "parent_id null => status tersedia" tetap konsisten
            // walau parent-nya dihapus paksa lewat force=1.
            $inventory->children()->update(['parent_id' => null, 'status' => 'tersedia']);
        }

        $kodeInventory = $inventory->kode_inventory;
        $inventory->delete();

        return response()->json(['message' => "Inventory {$kodeInventory} berhasil dihapus."]);
    }

    /**
     * POST /api/inventory/{inventory}/jual
     * Cuma berlaku buat item yang berdiri sendiri (bukan yang menempel ke
     * item lain, dan bukan yang masih punya item lain menempel padanya) --
     * item yang menempel ke induk gak lewat alur writeoff (kalau rusak
     * permanen, itu ditangani lewat alur lapor kerusakan biasa
     * -- menunggu_perbaikan -> diperbaiki/rusak_berat).
     */
    public function jual(Request $request, Inventory $inventory)
    {
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
            'supplier',
            'pemakaiSaatIni',
            'writeoff.penyetuju:id,name',
        ]));
    }

    /**
     * POST /api/inventory/{inventory}/pasang-pengganti-kelengkapan
     * eks AsetKelengkapanController@pasangPengganti. Nempelin kelengkapan
     * yang 'tersedia' ke barang utama tertentu.
     *
     * Kalau barang utama tujuan lagi ada pemakaian aktif (dipinjam),
     * kelengkapan ini ikut dianggap "dipinjam" ke pemakai yang sama --
     * BUKAN cuma status di-flip jadi 'dipakai', tapi beneran dibikinin
     * record InventoryPemakai sendiri (dengan foto_penerimaan wajib, sama
     * kayak serah-terima biasa di InventoryPemakaiController::store()).
     * Ini penting biar: (1) ada jejak siapa yang pegang & sejak kapan,
     * (2) item ini ke-cover cascade InventoryPemakaiController::kembalikan()
     * pas barang utama induknya dikembalikan nanti -- tanpa record ini,
     * status 'dipakai' bakal nyangkut selamanya karena cascade itu jalan
     * lewat query ke tabel InventoryPemakai, bukan cuma liat kolom status.
     */
    public function pasangPenggantiKelengkapan(Request $request, Inventory $inventory)
    {
        // item yang mau dipasang gak boleh sudah punya child sendiri (kalau
        // dia sendiri sudah jadi induk buat item lain, dia gak bisa
        // sekaligus jadi anak dari item lain -- cegah nesting > 1 level,
        // sama seperti aturan di validasiParent()).
        abort_if(
            $inventory->children()->exists(),
            422,
            'Item ini masih punya item lain yang menempel padanya, jadi tidak bisa dipasang ke induk lain.'
        );

        if ($inventory->status !== 'tersedia') {
            return response()->json([
                'message' => 'Kelengkapan ini tidak tersedia untuk dipasang.',
            ], 422);
        }

        $request->validate([
            'parent_id' => 'required|exists:inventory,id',
        ]);

        $parent = Inventory::findOrFail($request->input('parent_id'));
        // target parent juga harus item "induk murni" (belum menempel ke
        // item lain) -- simetris dengan aturan di atas.
        abort_if($parent->parent_id, 422, 'Item yang dipilih sebagai induk sudah menempel ke item lain, jadi tidak bisa dijadikan induk.');

        // cek dulu SEBELUM validasi foto -- foto cuma wajib kalau induknya
        // beneran lagi dipinjam (baru di situ ada record InventoryPemakai
        // yang perlu dibikin). Kalau induknya nganggur, gak ada apa-apa yang
        // perlu dicatat, jadi gak perlu maksa admin upload foto buat itu.
        $pemakaiIndukAktif = InventoryPemakai::where('inventory_id', $parent->id)
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->first();

        $rules = ['catatan_penerimaan' => 'nullable|string'];
        if ($pemakaiIndukAktif) {
            $rules['foto_penerimaan'] = 'required|array|min:1|max:3';
            $rules['foto_penerimaan.*'] = 'image|mimes:jpg,jpeg,png,webp|max:1024';
        }

        $validated = $request->validate($rules, [
            'foto_penerimaan.*.max' => 'Maksimal size foto adalah 1MB',
            'foto_penerimaan.required' => 'Foto penerimaan wajib diisi karena barang utama ini sedang dipinjam.',
        ]);

        DB::transaction(function () use ($inventory, $parent, $request, $validated, $pemakaiIndukAktif) {
            $inventory->update([
                'parent_id' => $parent->id,
                'status'  => $pemakaiIndukAktif ? 'dipakai' : 'tersedia',
            ]);

            if ($pemakaiIndukAktif) {
                $noStruk = $this->generateNoStruk('STJ', 'inventory_pemakai', 'no_struk_penerimaan');
                $fotoPaths = $this->simpanFotoBukti($request, 'foto_penerimaan', 'inventory-pemakai/penerimaan');

                InventoryPemakai::create([
                    'inventory_id' => $inventory->id,
                    'user_id' => $pemakaiIndukAktif->user_id,
                    'status' => 'disetujui',
                    'requested_by_user_id' => $request->user()?->id,
                    'no_struk_penerimaan' => $noStruk,
                    'tanggal_penerimaan' => now()->toDateString(),
                    'diterima_at' => now(),
                    'catatan_penerimaan' => $validated['catatan_penerimaan']
                        ?? 'Dipasang susulan -- mengikuti barang utama yang sedang dipinjam.',
                    'foto_penerimaan' => $fotoPaths,
                ]);
            }
        });

        return response()->json(
            $inventory->fresh()->load(['parent', 'supplier'])
        );
    }

    /**
     * Helper bareng buat update() & pasangPenggantiKelengkapan(): kalau
     * induk dari $inventory (Kelengkapan yang baru di-attach) lagi punya
     * pemakaian aktif (status 'disetujui', belum dikembalikan), buatkan
     * $inventory ini record InventoryPemakai yang senasib (user_id, foto
     * penerimaan sama seperti induknya). Kalau induknya lagi 'tersedia'
     * (gak ada pemakaian aktif), $inventory dibiarkan apa adanya -- gak ada
     * riwayat InventoryPemakai baru yang dibuat.
     *
     * CATATAN: helper ini TIDAK LAGI yang nentuin status 'dipakai' --
     * itu sekarang tanggung jawab selaraskanStatusByParent() (via
     * validasi()) atau di-set manual tepat setelah attach (lihat
     * pasangPenggantiKelengkapan()), berlaku terlepas dari induknya lagi
     * dipakai orang atau tidak. Helper ini murni soal riwayat pemakaian.
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
    }

    /**
     * Helper bareng buat update() & lepasDariInduk(): tutup pemakaian aktif
     * $inventory (Kelengkapan yang baru dilepas dari induknya) kalau ada --
     * simetris dengan ikutkanKelengkapanKePemakaianInduk() di atas. Kalau
     * $inventory kebetulan gak punya pemakaian aktif (misal statusnya emang
     * udah 'tersedia' dari awal, gak pernah ikut kepakai induknya), fungsi
     * ini idempotent -- query InventoryPemakai-nya otomatis gak nemu
     * apa-apa.
     *
     * CATATAN: status 'tersedia' sendiri TIDAK LAGI diset di sini --
     * itu sekarang tanggung jawab selaraskanStatusByParent() (via
     * validasi(), dipanggil sebelum helper ini di update()) atau di-set
     * manual tepat sebelum/sesudah detach di caller lain
     * (lepasDariInduk()). Helper ini murni beres-beres riwayat
     * InventoryPemakai.
     *
     * Dipanggil di DALAM DB::transaction masing-masing caller.
     */
    protected function lepasKelengkapanDariPemakaianAktif(Inventory $inventory, string $catatanPengembalian): void
    {
        InventoryPemakai::where('inventory_id', $inventory->id)
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->update([
                'tanggal_pengembalian' => now(),
                'dikembalikan_at' => now(),
                'catatan_pengembalian' => $catatanPengembalian,
            ]);
    }

    /**
     * POST /api/inventory/{inventory}/lepas-dari-induk
     * Satu-satunya jalan RESMI buat ngosongin parent_id Kelengkapan yang
     * lagi nempel -- manual oleh admin, TIDAK ada proses otomatis lain
     * (termasuk lapor rusak / hasil penanganan rusak_berat) yang boleh
     * ngelakuin ini secara implisit. (update() lewat form Edit generik
     * SEKARANG JUGA bisa ngosongin parent_id -- lihat komentar
     * $parentBaruDilepas di update() -- endpoint ini tetap dipertahankan
     * sebagai jalur cepat khusus dari tombol "Lepas dari Induk".)
     *
     * REVISI: dulu endpoint ini murni mutusin parent_id + nutup pemakaian
     * aktif TANPA nyentuh status (dibiarkan apa adanya). Itu bikin bug --
     * begitu ada attach otomatis yang nyetel status 'dipakai' terikat ke
     * pemakaian induk, kelengkapan yang dilepas dari sini nyangkut
     * selamanya di status 'dipakai' meski udah gak nempel ke mana-mana --
     * gak bisa diserahkan/dipinjam lagi. Sekarang status dipaksa balik ke
     * 'tersedia' langsung di sini -- SEJALAN dengan invariant di
     * selaraskanStatusByParent(): parent_id null => status tersedia.
     */
    public function lepasDariInduk(Request $request, Inventory $inventory)
    {
        abort_unless($inventory->parent_id, 422, 'Item ini tidak sedang menempel ke induk manapun.');

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
                'status' => 'tersedia',
            ]);

            $this->lepasKelengkapanDariPemakaianAktif($inventory, 'Dikembalikan otomatis — kelengkapan dilepas dari induk oleh admin.');
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
            $inventory->fresh()->load(['kategori', 'supplier'])
        );
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
        // PENTING: normalisasi parent_id HARUS dicek $request->has() dulu --
        // beda dengan field lain di bawah (yang emang selalu dikirim FE tiap
        // submit), parent_id kadang gak ikut disertakan sama sekali di
        // payload kalau admin cuma edit field lain (nama, warna, dst) dari
        // form yang sama. Kalau langsung di-merge tanpa cek has() dulu,
        // $request->parent_id pada field yang gak dikirim otomatis kebaca
        // null, lalu merge() ini bakal MEMAKSA parent_id jadi null di
        // request -- $validated jadi selalu punya 'parent_id' => null
        // walau field itu gak pernah disentuh sama sekali. Efeknya:
        // $parentBaruDilepas di update() salah kedeteksi true (dikira admin
        // baru aja lepas parent_id), padahal admin cuma edit field lain --
        // status kelengkapan ke-reset ke 'tersedia' secara gak sengaja.
        if ($request->has('parent_id')) {
            $request->merge([
                'parent_id' => $request->parent_id === '' ? null : $request->parent_id,
            ]);
        }

        $request->merge([
            'serial_number' => $request->serial_number === '' ? null : $request->serial_number,
            'kategori_id' => $request->kategori_id === '' ? null : $request->kategori_id,
        ]);

        $validated = $request->validate([
            'parent_id' => 'nullable|exists:inventory,id',
            'kategori_id' => 'required|exists:kategori,id',
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
            'no_surat_jalan' => 'nullable|string|max:255',
            'no_good_receive' => 'nullable|string|max:255',
            'status' => 'nullable|in:tersedia,dipakai,menunggu_perbaikan,diperbaiki,rusak_berat',
            'tanggal_rusak' => 'nullable|date',
        ]);

        $this->validasiParent($validated, $inventory);
        $this->selaraskanStatusByParent($validated, $inventory);

        return $validated;
    }

    /**
     * REVISI Fase 2: constraint lama dari dokumen migrasi #2.3 (parent_id
     * cuma boleh diisi kalau baris ini kategorinya Kelengkapan & target
     * parent_id-nya kategorinya Barang Utama) SUDAH DICABUT -- kategori gak
     * lagi nentuin siapa boleh nempel ke siapa. Sekarang SEMUA item bebas
     * jadi induk atau menempel ke item lain, apapun kategorinya. Constraint
     * yang TETAP dipertahankan (struktural, bukan kategori):
     * (a) item yang mau dikasih parent_id gak boleh sudah punya child
     *     sendiri -- kalau dia sendiri sudah jadi induk, dia gak bisa
     *     sekaligus jadi anak dari item lain (cegah nesting > 1 level).
     * (b) simetris: target parent_id-nya juga harus item "induk murni" --
     *     belum menempel ke item lain (cegah rantai A->B->C).
     * (c) gak boleh nempel ke diri sendiri (cegah circular).
     */
    protected function validasiParent(array $validated, ?Inventory $inventory): void
    {
        if (empty($validated['parent_id'])) {
            return;
        }

        abort_if(
            $inventory && $validated['parent_id'] == $inventory->id,
            422,
            'Item tidak boleh menempel ke dirinya sendiri.'
        );

        abort_if(
            $inventory && $inventory->children()->exists(),
            422,
            'Item ini masih punya item lain yang menempel padanya, jadi tidak bisa dipasang menempel ke induk lain. Lepas dulu semua item yang menempel sebelum memasang item ini ke induk lain.'
        );

        $parent = Inventory::find($validated['parent_id']);
        abort_unless($parent, 422, 'parent_id yang dipilih tidak ditemukan.');
        abort_if(
            $parent->parent_id,
            422,
            'Item yang dipilih sebagai induk sudah menempel ke item lain, jadi tidak bisa dijadikan induk (maksimal 1 level nesting).'
        );
    }

    /**
     * Invariant: item yang MENEMPEL ke item lain (parent_id terisi)
     * statusnya HARUS 'dipakai', dan yang berdiri sendiri/jadi induk
     * (parent_id kosong) HARUS 'tersedia' -- terlepas dari kategorinya
     * apa, dan terlepas dari apakah induknya sendiri lagi dipinjam orang
     * atau tidak. Dipanggil dari validasi() biar otomatis kepakai di
     * store() (item baru dibuat langsung menempel) maupun update()
     * (attach/detach lewat form Edit generik), tanpa perlu diulang manual
     * di masing-masing caller.
     *
     * REVISI Fase 2: dulu cuma berlaku buat kategori Kelengkapan (Barang
     * Utama gak disentuh krn emang gak pernah bisa punya parent_id sama
     * sekali). Sekarang kategori gak lagi nentuin apa-apa -- invariant ini
     * berlaku SERAGAM buat SEMUA item, apapun kategorinya, murni dari ada/
     * tidaknya parent_id. Efeknya: item yang dulu berkategori Barang Utama
     * yang diedit lewat form generik ini sekarang JUGA kena auto-sync
     * status ke 'tersedia' selama parent_id-nya kosong -- perhatikan ini
     * pas testing Fase 3/4, terutama kalau ada alur lain yang masih
     * berharap status item semacam itu bebas diisi manual lewat form
     * generik ini.
     */
    protected function selaraskanStatusByParent(array &$validated, ?Inventory $inventory): void
    {
        $parentId = array_key_exists('parent_id', $validated)
            ? $validated['parent_id']
            : $inventory?->parent_id;

        $validated['status'] = $parentId ? 'dipakai' : 'tersedia';
    }
}