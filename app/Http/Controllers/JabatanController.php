<?php

namespace App\Http\Controllers;

use App\Models\Jabatan;
use Illuminate\Http\Request;

class JabatanController extends Controller
{
    public function index()
    {
        return response()->json(Jabatan::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jabatan,nama',
        ]);

        $jabatan = Jabatan::create($validated);

        return response()->json($jabatan, 201);
    }

    public function update(Request $request, Jabatan $jabatan)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:jabatan,nama,' . $jabatan->id,
        ]);

        $jabatan->update($validated);

        return response()->json($jabatan);
    }

    public function destroy(Jabatan $jabatan)
    {
        $jabatan->delete();

        return response()->json(['message' => "Jabatan {$jabatan->nama} berhasil dihapus."]);
    }
}