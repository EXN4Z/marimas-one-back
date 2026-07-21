<?php

namespace App\Http\Controllers;

use App\Models\JenisAset;
use Illuminate\Http\Request;

class JenisAsetController extends Controller
{
    public function index()
    {
        return response()->json(JenisAset::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama',
        ]);

        $jenis = JenisAset::create($validated);

        return response()->json($jenis, 201);
    }

    public function update(Request $request, JenisAset $jenisAset)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jenis_aset,nama,' . $jenisAset->id,
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