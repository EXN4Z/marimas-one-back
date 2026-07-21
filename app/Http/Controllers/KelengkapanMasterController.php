<?php

namespace App\Http\Controllers;

use App\Models\KelengkapanMaster;
use Illuminate\Http\Request;

class KelengkapanMasterController extends Controller
{
    public function index()
    {
        return response()->json(KelengkapanMaster::orderBy('nama')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:kelengkapan_master,nama',
        ]);

        $kelengkapan = KelengkapanMaster::create($validated);

        return response()->json($kelengkapan, 201);
    }

    public function update(Request $request, KelengkapanMaster $kelengkapanMaster)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:kelengkapan_master,nama,' . $kelengkapanMaster->id,
        ]);

        $kelengkapanMaster->update($validated);

        return response()->json($kelengkapanMaster);
    }

    public function destroy(KelengkapanMaster $kelengkapanMaster)
    {
        $kelengkapanMaster->delete();

        return response()->json(['message' => "Kelengkapan {$kelengkapanMaster->nama} berhasil dihapus."]);
    }
}