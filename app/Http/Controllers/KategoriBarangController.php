<?php

namespace App\Http\Controllers;

use App\Models\KategoriBarang;
use Illuminate\Http\Request;

class KategoriBarangController extends Controller
{
    public function index()
    {
        return response()->json(KategoriBarang::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:kategori_barang,nama',
        ]);

        $kategori = KategoriBarang::create($validated);

        return response()->json($kategori, 201);
    }

    public function update(Request $request, KategoriBarang $kategoriBarang)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:kategori_barang,nama,' . $kategoriBarang->id,
        ]);

        $kategoriBarang->update($validated);

        return response()->json($kategoriBarang);
    }

    public function destroy(KategoriBarang $kategoriBarang)
    {
        $kategoriBarang->delete();

        return response()->json(['message' => "Kategori {$kategoriBarang->nama} berhasil dihapus."]);
    }
}