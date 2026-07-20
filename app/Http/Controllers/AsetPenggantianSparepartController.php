<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\AsetPenggantianSparepart;
use Illuminate\Http\Request;

class AsetPenggantianSparepartController extends Controller
{
    /**
     * POST /api/aset/{aset}/penggantian-sparepart
     */
    public function store(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'tanggal' => 'required|date',
            'nama_sparepart' => 'required|string',
            'keterangan' => 'nullable|string',
            'biaya' => 'nullable|numeric|min:0',
        ]);

        $sparepart = AsetPenggantianSparepart::create([
            'aset_id' => $aset->id,
            ...$validated,
        ]);

        return response()->json($sparepart, 201);
    }

    public function destroy(AsetPenggantianSparepart $asetPenggantianSparepart)
    {
        $asetPenggantianSparepart->delete();

        return response()->json(['message' => 'Riwayat penggantian sparepart berhasil dihapus.']);
    }
}