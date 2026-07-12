<?php

namespace App\Http\Controllers;

use App\Models\Divisi;
use Illuminate\Http\Request;

class DivisiController extends Controller
{
    public function index()
    {
        return response()->json(Divisi::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:divisi,nama',
        ]);

        $divisi = Divisi::create($validated);

        return response()->json($divisi, 201);
    }

    public function update(Request $request, Divisi $divisi)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:divisi,nama,' . $divisi->id,
        ]);

        $divisi->update($validated);

        return response()->json($divisi);
    }

    public function destroy(Divisi $divisi)
    {
        $divisi->delete();

        return response()->json(['message' => "Divisi {$divisi->nama} berhasil dihapus."]);
    }
}