<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;

use App\Models\JenisAset;
use App\Models\Kategori;
use Illuminate\Http\Request;

class JenisAsetController extends Controller
{
    /**
     * GET /api/jenis-aset
     * GET /api/jenis-aset?kategori=aset_utama   -- dropdown Tambah Aset
     * GET /api/jenis-aset?kategori=kelengkapan  -- checklist kelengkapan di form peminjaman
     */
    public function index(Request $request)
    {
        $query = JenisAset::orderBy('nama');

        if ($request->filled('kategori')) {
            $query->kategoriKode($request->string('kategori'));
        }

        return response()->json($query->get());
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama',
            'kategori' => 'required|in:aset_utama,kelengkapan',
        ]);

        $jenis = JenisAset::create([
            'nama' => $validated['nama'],
            'kategori_id' => $this->kategoriIdFromKode($validated['kategori']),
        ]);

        return response()->json($jenis, 201);
    }

    public function update(Request $request, JenisAset $jenisAset)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama,' . $jenisAset->id,
            'kategori' => 'required|in:aset_utama,kelengkapan',
        ]);

        $jenisAset->update([
            'nama' => $validated['nama'],
            'kategori_id' => $this->kategoriIdFromKode($validated['kategori']),
        ]);

        return response()->json($jenisAset);
    }

    public function destroy(JenisAset $jenisAset)
    {
        $jenisAset->delete();

        return response()->json(['message' => "Jenis {$jenisAset->nama} berhasil dihapus."]);
    }

    // Terima 'kategori' sebagai kode ('aset_utama'/'kelengkapan') dari
    // request lama, cari id-nya di tabel kategori yang sekarang terpisah.
    private function kategoriIdFromKode(string $kode): int
    {
        return Kategori::where('kode', $kode)->value('id');
    }
}