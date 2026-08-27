<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\MasterData\Inventory;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryPenanganan;
use App\Models\User;
use App\Notifications\AsetKerusakanDilaporkan;
use App\Notifications\AsetKerusakanSelesai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryPenangananController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * GET /api/inventory-penanganan
     * Admin & HR: semua laporan penanganan, lintas karyawan (perilaku lama,
     * gak berubah).
     * Non-admin/HR (karyawan/manajer): dibatasi cuma laporan yang terkait
     * pemakaian dia sendiri (inventory_pemakai.user_id == dia), biar
     * karyawan gak bisa lihat laporan kerusakan/riwayat perbaikan milik
     * karyawan lain. Discoping DI SINI (bukan cuma di middleware
     * routes/api.php), soalnya middleware cuma ngatur SIAPA yang boleh
     * manggil endpoint-nya, bukan DATA APA yang boleh dia lihat.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdminAtauHr = in_array($user?->role, ['admin', 'hr'], true);

        $query = InventoryPenanganan::with(['inventory', 'pemakai.user'])
            ->orderByDesc('tanggal_lapor');

        if (!$isAdminAtauHr) {
            $query->whereHas('pemakai', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        return response()->json($query->get());
    }

    // Tab "Rusak" di halaman Foto Inventory — versi paginated & bisa
    // dicari, beda dari index() di atas yang narik SEMUA data buat halaman
    // penanganan/riwayat. foto di sini masih 1 file per laporan (bukan
    // array kayak foto_penerimaan/foto_pengembalian di InventoryPemakai).
    public function foto(Request $request)
    {
        $query = InventoryPenanganan::with(['inventory', 'pemakai.user'])
            ->whereNotNull('foto')
            ->orderByDesc('tanggal_lapor');

        if ($search = $request->input('search')) {
            $query->whereHas('inventory', function ($q) use ($search) {
                $q->where('kode_inventory', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, (int) $request->input('per_page', 12));
        $data = $query->paginate($perPage);

        return response()->json($data);
    }

    // peminjam ATAU admin lapor kerusakan barang -- berlaku buat Barang Utama
    // MAUPUN Kelengkapan, baik yang berdiri sendiri maupun yang masih nempel
    // ke induk. Kelengkapan yang nempel dulu punya jalur sendiri yang
    // langsung final (InventoryController@laporRusakKelengkapan, gak ada
    // opsi "diperbaiki") -- sekarang disatukan ke sini biar konsisten:
    // lapor -> menunggu_perbaikan -> terima -> diperbaiki -> selesai (hasil
    // 'diperbaiki' ATAU 'rusak_berat'). Kalau hasilnya 'rusak_berat', baru di
    // situ kelengkapan yang nempel otomatis dicopot dari induk + otomatis
    // dikembalikan (lihat update() di bawah). Kalau berhasil 'diperbaiki',
    // parent_id TIDAK disentuh -- tetap nempel ke induknya.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_id' => 'required|exists:inventory,id',
            'jenis_kerusakan' => 'required|in:software,hardware',
            'keluhan' => 'required|string',
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:1024',
        ], [
            'foto.required' => 'foto harus diisi',
            'foto.max' => 'Size foto maksimal 1MB',
            'foto.mimes' => 'foto harus berupa jpg,jpeg,png,webp',
        ]);

        $user = $request->user();
        $inventory = Inventory::with('kategori')->findOrFail($validated['inventory_id']);

        $bolehLaporLewatSini = $inventory->isBarangUtama() || $inventory->isKelengkapan();

        abort_unless($bolehLaporLewatSini, 422, 'Laporan kerusakan lewat endpoint ini hanya berlaku untuk Barang Utama atau Kelengkapan.');

        // cegah lapor dobel kalau item ini masih ada laporan yang belum
        // selesai ditangani (baik yang masih menunggu diterima admin,
        // maupun yang sudah diterima/sedang diperbaiki)
        if (in_array($inventory->status, ['menunggu_perbaikan', 'diperbaiki', 'rusak_berat'], true)) {
            $pesan = $inventory->status === 'rusak_berat'
                ? 'Barang ini sudah dinyatakan rusak berat, tidak bisa dilaporkan kerusakan lagi.'
                : 'Barang ini sudah dilaporkan rusak dan sedang dalam penanganan.';

            return response()->json(['message' => $pesan], 422);
        }

        // cegah lapor 2x: kalau item ini masih ada laporan yang belum kelar
        // (belum ditandai selesai), tolak laporan baru.
        $sudahAdaLaporanAktif = InventoryPenanganan::where('inventory_id', $validated['inventory_id'])
            ->whereNull('tanggal_selesai')
            ->exists();

        if ($sudahAdaLaporanAktif) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Barang ini sudah ada laporan kerusakan yang masih diproses. Tunggu sampai selesai sebelum lapor lagi.',
            ]);
        }

        // cek user emang lagi pegang item ini via pemakaian aktif (status
        // disetujui, belum dikembalikan).
        $pemakai = InventoryPemakai::where('inventory_id', $validated['inventory_id'])
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->where('user_id', $user->id)
            ->first();

        $penanganan = DB::transaction(function () use ($validated, $pemakai, $request) {
            $fotoPath = $request->file('foto')->store('inventory-penanganan', 'public');
            // nullable: laporan kerusakan bisa juga muncul pas item lagi
            // nganggur (audit gudang)
            $penanganan = InventoryPenanganan::create([
                'inventory_id' => $validated['inventory_id'],
                'inventory_pemakai_id' => $pemakai->id ?? null,
                'jenis_kerusakan' => $validated['jenis_kerusakan'],
                'keluhan' => $validated['keluhan'],
                'foto' => $fotoPath,
                'tanggal_lapor' => now(),
                'lapor_at' => now(),
            ]);

            // item langsung ganti status "menunggu_perbaikan" biar kelihatan
            // di tabel (dan biar tombol "Lapor Kerusakan" ilang, gak bisa
            // dobel lapor). Kelengkapan yang nempel TIDAK ikut berubah
            // (keputusan #1 di dokumen migrasi) -- tetap di status
            // terakhirnya.
            Inventory::whereKey($validated['inventory_id'])->update(['status' => 'menunggu_perbaikan']);

            return $penanganan;
        });

        // notif ke manajer/hr/admin tiap ada laporan kerusakan masuk
        // (database + broadcast + web push, biar kekirim walau admin lagi
        // di luar device) try-catch: laporan yang SUDAH tersimpan di atas
        // jangan ikut gagal kalau notif error.
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])->get(),
                new AsetKerusakanDilaporkan($penanganan->load(['inventory', 'pemakai.user']))
            );
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi laporan kerusakan inventory', [
                'inventory_penanganan_id' => $penanganan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json($penanganan->load(['inventory', 'pemakai.user']), 201);
    }

    // admin: terima & mulai tangani laporan -> item jadi "diperbaiki" (sedang diperbaiki)
    public function terima(InventoryPenanganan $inventoryPenanganan)
    {
        if ($inventoryPenanganan->tanggal_diterima) {
            return response()->json(['message' => 'Laporan ini sudah diterima sebelumnya.'], 422);
        }

        if ($inventoryPenanganan->tanggal_selesai) {
            return response()->json(['message' => 'Laporan ini sudah selesai ditangani.'], 422);
        }

        DB::transaction(function () use ($inventoryPenanganan) {
            $inventoryPenanganan->update(['tanggal_diterima' => now(), 'diterima_at' => now()]);
            Inventory::whereKey($inventoryPenanganan->inventory_id)->update(['status' => 'diperbaiki']);
        });

        return response()->json($inventoryPenanganan->fresh()->load(['inventory', 'pemakai.user']));
    }

    // admin: tandai penanganan selesai + isi hasil/biaya, generate no_struk
    // (dicek di route middleware, lihat routes/api.php)
    public function update(Request $request, InventoryPenanganan $inventoryPenanganan)
    {
        $validated = $request->validate([
            'tanggal_selesai' => 'nullable|date',
            'harga_jasa' => [
                'nullable', 'numeric', 'min:0',
                Rule::requiredIf(fn () => $request->input('hasil') === 'diperbaiki'),
            ],
            'biaya_komponen' => [
                'nullable', 'numeric', 'min:0',
                Rule::requiredIf(fn () => $request->input('hasil') === 'diperbaiki'),
            ],
            'hasil' => 'nullable|in:diperbaiki,rusak_berat',
            'catatan' => 'nullable|string',
        ], [
            'harga_jasa.required' => 'Biaya jasa wajib diisi kalau hasilnya diperbaiki.',
            'biaya_komponen.required' => 'Biaya komponen wajib diisi kalau hasilnya diperbaiki.',
        ]);

        // rusak berat = gak ada biaya perbaikan (emang gak diperbaiki), jadi
        // paksa null di server juga -- jangan cuma andelin frontend yang
        // disable input-nya, biar gak ada celah kirim biaya manual lewat API.
        if (($validated['hasil'] ?? null) === 'rusak_berat') {
            $validated['harga_jasa'] = null;
            $validated['biaya_komponen'] = null;
        }

        // kalau tanggal_selesai gak dikirim eksplisit, anggap "tandai selesai sekarang"
        if (!$request->has('tanggal_selesai')) {
            $validated['tanggal_selesai'] = now();
        }

        // simpan status sebelumnya, biar kita tau ini transisi PERTAMA kali
        // ke "selesai" (bukan admin cuma edit catatan setelahnya)
        $sudahSelesaiSebelumnya = (bool) $inventoryPenanganan->tanggal_selesai;

        DB::transaction(function () use ($inventoryPenanganan, $validated) {
            // struk cuma digenerate sekali, pas pertama kali ditandai selesai
            if (!$inventoryPenanganan->no_struk && ($validated['tanggal_selesai'] ?? null)) {
                $validated['no_struk'] = $this->generateNoStruk('PNG', 'inventory_penanganan', 'no_struk');
            }

            // waktu akurat buat riwayat — selesai_at dicatat terpisah dari
            // tanggal_selesai (yang cuma tanggal) biar "X jam lalu" di panel
            // riwayat gak ngitung dari tengah malam.
            if ($validated['tanggal_selesai'] ?? null) {
                $validated['selesai_at'] = now();
            }

            // jaga-jaga: kalau admin langsung tandai selesai tanpa lewat
            // tombol "Terima" dulu, tetap isi tanggal_diterima biar datanya
            // konsisten.
            if (($validated['tanggal_selesai'] ?? null) && !$inventoryPenanganan->tanggal_diterima) {
                $validated['tanggal_diterima'] = now();
                $validated['diterima_at'] = now();
            }

            $inventoryPenanganan->update($validated);

            // begitu ditandai selesai: kalau hasilnya rusak berat, item gak
            // balik "tersedia" (gak bisa dipinjem lagi) — status khusus
            // 'rusak_berat', tetep nempel di baris Inventory yang sama.
            // Selain itu (diperbaiki), balikin status ke normal: kalau masih
            // ada pemakai aktif yang belum ngembaliin ya "dipakai" lagi,
            // kalau enggak ya balik "tersedia" — bukan asal "tersedia" biar
            // gak nyalahin data peminjaman yang masih jalan. Kelengkapan yang
            // nempel di item ini SAMA SEKALI tidak diubah statusnya di sini.
            if ($validated['tanggal_selesai'] ?? null) {
                $hasilAkhir = $validated['hasil'] ?? $inventoryPenanganan->hasil;

                if ($hasilAkhir === 'rusak_berat') {
                    $updateData = ['status' => 'rusak_berat'];

                    // BARU: Kelengkapan yang masih nempel ke induk -- kalau
                    // gagal diperbaiki (rusak_berat), otomatis dicopot dari
                    // induknya (parent_id null) juga, bukan cuma diganti
                    // status. Barang Utama gak kena ini (parent_id dia emang
                    // selalu null). Kelengkapan yang BERHASIL diperbaiki
                    // ('diperbaiki', cabang else di bawah) parent_id-nya
                    // TIDAK disentuh -- tetap nempel ke induknya.
                    $itemRusak = Inventory::find($inventoryPenanganan->inventory_id);
                    if ($itemRusak && $itemRusak->isKelengkapan() && $itemRusak->parent_id) {
                        $updateData['parent_id'] = null;
                    }

                    Inventory::whereKey($inventoryPenanganan->inventory_id)->update($updateData);

                    // rusak berat = item gak dipakai siapa-siapa lagi. Tutup
                    // paksa record inventory_pemakai yang masih aktif (belum
                    // dikembalikan) biar "Dipakai Oleh" & riwayat peminjaman
                    // ikut konsisten.
                    InventoryPemakai::where('inventory_id', $inventoryPenanganan->inventory_id)
                        ->where('status', 'disetujui')
                        ->whereNull('tanggal_pengembalian')
                        ->update([
                            'tanggal_pengembalian' => now(),
                            'dikembalikan_at' => now(),
                            'catatan_pengembalian' => 'Dikembalikan otomatis — barang dinyatakan rusak berat.',
                        ]);
                } else {
                    $masihDipakai = InventoryPemakai::where('inventory_id', $inventoryPenanganan->inventory_id)
                        ->where('status', 'disetujui')
                        ->whereNull('tanggal_pengembalian')
                        ->exists();

                    Inventory::whereKey($inventoryPenanganan->inventory_id)
                        ->update(['status' => $masihDipakai ? 'dipakai' : 'tersedia']);
                }
            }
        });

        $inventoryPenanganan->refresh();

        // notif ke pelapor begitu penanganan pertama kali ditandai selesai
        // (baik diperbaiki maupun rusak_berat -- keduanya "selesai ditangani")
        if (($validated['tanggal_selesai'] ?? null) && !$sudahSelesaiSebelumnya) {
            try {
                $pelapor = $inventoryPenanganan->pemakai?->user;

                if ($pelapor) {
                    $pelapor->notify(new AsetKerusakanSelesai(
                        $inventoryPenanganan->load(['inventory', 'pemakai.user'])
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi penanganan inventory selesai', [
                    'inventory_penanganan_id' => $inventoryPenanganan->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return response()->json($inventoryPenanganan->fresh()->load(['inventory', 'pemakai.user']));
    }

    public function destroy(InventoryPenanganan $inventoryPenanganan)
    {
        $inventoryPenanganan->delete();

        return response()->json(['message' => 'Laporan penanganan berhasil dihapus.']);
    }
}