<?php

namespace App\Http\Controllers;

use App\Models\Departemen;
use Illuminate\Http\Request;

class DepartemenController extends Controller
{
    public function index()
    {
        return response()->json(Departemen::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:departemen,nama',
        ]);

        $departemen = Departemen::create($validated);

        return response()->json($departemen, 201);
    }

    public function update(Request $request, Departemen $departemen)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:departemen,nama,' . $departemen->id,
        ]);

        $departemen->update($validated);

        return response()->json($departemen);
    }

    public function destroy(Departemen $departemen)
    {
        $departemen->delete();

        return response()->json(['message' => "Departemen {$departemen->nama} berhasil dihapus."]);
    }
}