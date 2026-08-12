<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;

use App\Models\JenisAset;
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
            $query->where('kategori', $request->string('kategori'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama',
            'kategori' => 'required|in:aset_utama,kelengkapan',
        ]);

        $jenis = JenisAset::create($validated);

        return response()->json($jenis, 201);
    }

    public function update(Request $request, JenisAset $jenisAset)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama,' . $jenisAset->id,
            'kategori' => 'required|in:aset_utama,kelengkapan',
        ]);

        $jenisAset->update($validated);

        return response()->json($jenisAset);
    }

    public function destroy(JenisAset $jenisAset)
    {
        $jenisAset->delete();

        return response()->json(['message' => "Jenis {$jenisAset->nama} berhasil dihapus."]);
    }
}