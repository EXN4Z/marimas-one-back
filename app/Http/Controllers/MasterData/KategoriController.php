<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;

use App\Models\MasterData\Kategori;
use Illuminate\Http\Request;

/**
 * Kategori barang di Inventory (mis. "Laptop", "Charger", "Speaker" --
 * lihat 13 kategori seed di migration reset_and_seed_kategori_baru). Nama
 * bebas apa saja, dikelola admin lewat CRUD biasa di Master Data, gak ada
 * validasi tipe/golongan khusus (kategori TIDAK LAGI menentukan struktur
 * induk/menempel item -- itu murni dari Inventory::parent_id, lihat
 * Inventory::isInduk()/isChild()).
 *
 * Ini controller yang AKTIF & dirutekan (lihat routes/api.php).
 * MasterKategoriController (tabel master_kategori) sudah dihapus total --
 * dulu sempat ada 2 controller kategori paralel, sekarang cuma ini yang
 * dipakai.
 */
class KategoriController extends Controller
{
    // GET /api/kategori — dipakai buat dropdown pilih Kategori waktu
    // bikin/edit Inventory.
    public function index()
    {
        return response()->json(Kategori::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        $kategori = Kategori::create($validated);

        return response()->json($kategori, 201);
    }

    public function update(Request $request, Kategori $kategori)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
        ]);

        $kategori->update($validated);

        return response()->json($kategori->fresh());
    }

    /**
     * DELETE /api/kategori/{kategori}
     * FK ke inventory.kategori_id pakai restrictOnDelete di DB (bakal
     * error kalau dipaksa hapus lewat SQL langsung), jadi dicek manual
     * dulu di sini biar pesan errornya jelas & rapi buat user (422,
     * bukan error SQL constraint mentah).
     */
    public function destroy(Kategori $kategori)
    {
        if ($kategori->inventory()->exists()) {
            return response()->json([
                'message' => 'Kategori ini masih dipakai oleh data Inventory dan tidak bisa dihapus.',
            ], 422);
        }

        $namaKategori = $kategori->nama;
        $kategori->delete();

        return response()->json(['message' => "Kategori {$namaKategori} berhasil dihapus."]);
    }
}