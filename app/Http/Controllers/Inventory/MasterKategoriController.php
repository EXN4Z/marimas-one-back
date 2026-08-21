<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;

use App\Models\MasterKategori;
use Illuminate\Http\Request;

class MasterKategoriController extends Controller
{
    // GET /api/master-kategori -- ?kategori_id=1 buat filter dropdown per
    // Kategori (mis. cuma nunjukin jenis-jenis Kelengkapan waktu bikin
    // Kelengkapan baru).
    public function index(Request $request)
    {
        $query = MasterKategori::with('kategori')->orderBy('nama');

        if ($request->filled('kategori_id')) {
            $query->where('kategori_id', $request->query('kategori_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'kode' => 'nullable|string|max:10',
            'kategori_id' => 'required|exists:kategori,id',
        ]);

        $masterKategori = MasterKategori::create($validated);

        return response()->json($masterKategori->load('kategori'), 201);
    }

    public function update(Request $request, MasterKategori $masterKategori)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'kode' => 'nullable|string|max:10',
            'kategori_id' => 'required|exists:kategori,id',
        ]);

        $masterKategori->update($validated);

        return response()->json($masterKategori->fresh()->load('kategori'));
    }

    /**
     * DELETE /api/master-kategori/{masterKategori}
     * FK ke inventory.master_kategori_id pakai restrictOnDelete (lihat
     * dokumen migrasi #2.2) -- kalau masih dipakai baris Inventory, DB bakal
     * nolak & lempar QueryException. Dicek manual dulu di sini biar pesan
     * errornya jelas buat user, bukan error SQL mentah.
     */
    public function destroy(MasterKategori $masterKategori)
    {
        if ($masterKategori->inventory()->exists()) {
            return response()->json([
                'message' => 'Master Kategori ini masih dipakai oleh data Inventory dan tidak bisa dihapus.',
            ], 422);
        }

        $namaMasterKategori = $masterKategori->nama;
        $masterKategori->delete();

        return response()->json(['message' => "Master Kategori {$namaMasterKategori} berhasil dihapus."]);
    }
}