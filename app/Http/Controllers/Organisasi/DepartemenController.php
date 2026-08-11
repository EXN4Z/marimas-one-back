<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Models\Departemen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function update(Request $request, Departemen $departeman)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:departemen,nama,' . $departeman->id,
        ]);

        $departeman->update($validated);

        return response()->json($departeman);
    }

    public function destroy(Departemen $departeman)
    {
        $departeman->delete();

        return response()->json(['message' => "Departemen {$departeman->nama} berhasil dihapus."]);
    }
}