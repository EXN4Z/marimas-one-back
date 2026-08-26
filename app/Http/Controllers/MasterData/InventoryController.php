<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;

use App\Models\MasterData\Inventory;
use App\Models\MasterData\Kategori;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryWriteoff;
use App\Models\User;
use App\Notifications\AsetKelengkapanKerusakanDilaporkan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class InventoryController extends Controller
{
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
            'parent:id,kode_inventory,merek,tipe,nama',
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

            $inventory->update($validated);
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
     * eks AsetKelengkapanController@laporRusak. Cuma berlaku buat
     * Kelengkapan. Lepas otomatis dari parent (kalau ada), tutup paksa
     * peminjaman aktif yang nempel di kelengkapan ini, status -> 'rusak'.
     * Final, gak ada opsi "diperbaiki" buat kelengkapan. Status barang utama
     * TIDAK terpengaruh sama sekali (keputusan #1 di dokumen migrasi).
     */
    public function laporRusakKelengkapan(Inventory $inventory)
    {
        abort_unless($inventory->isKelengkapan(), 422, 'Hanya Kelengkapan yang bisa dilaporkan lewat endpoint ini.');

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
                $parentLabel = trim(($parent->kode_inventory ?? '') . ' ' . ($parent->merek ?? ''));
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
            $adaPemakaiAktif = InventoryPemakai::where('inventory_id', $parent->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->exists();

            $inventory->update([
                'parent_id' => $parent->id,
                'status'  => $adaPemakaiAktif ? 'dipakai' : 'tersedia',
            ]);
        });

        return response()->json(
            $inventory->fresh()->load(['parent', 'lokasiKantor', 'supplier'])
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
                    ->orWhere('nama', 'like', "%{$search}%")
                    ->orWhere('merek', 'like', "%{$search}%");
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
            'kategori_id' => 'nullable|exists:kategori,id',
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'nama' => 'nullable|string|max:255',
            'merek' => 'nullable|string|max:255',
            'tipe' => 'nullable|string|max:255',
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