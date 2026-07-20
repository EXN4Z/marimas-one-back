<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\AsetPerbaikan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsetPerbaikanController extends Controller
{
    /**
     * POST /api/aset/{aset}/perbaikan
     */
    public function store(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'tanggal_perbaikan' => 'required|date',
            'keterangan_kerusakan' => 'required|string',
            'teknisi_vendor' => 'nullable|string',
            'biaya' => 'nullable|numeric|min:0',
        ]);

        $perbaikan = DB::transaction(function () use ($aset, $validated) {
            $perbaikan = AsetPerbaikan::create([
                'aset_id' => $aset->id,
                ...$validated,
                'status' => 'proses',
            ]);

            $aset->update(['status' => 'rusak']);

            return $perbaikan;
        });

        return response()->json($perbaikan, 201);
    }

    /**
     * PATCH /api/aset-perbaikan/{asetPerbaikan}/selesai
     * Tandai perbaikan selesai, aset balik jadi 'tersedia'.
     */
    public function selesai(Request $request, AsetPerbaikan $asetPerbaikan)
    {
        $validated = $request->validate([
            'tanggal_selesai' => 'required|date',
            'biaya' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($asetPerbaikan, $validated) {
            $asetPerbaikan->update([
                'status' => 'selesai',
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'biaya' => $validated['biaya'] ?? $asetPerbaikan->biaya,
            ]);

            $asetPerbaikan->aset()->update(['status' => 'tersedia']);
        });

        return response()->json($asetPerbaikan);
    }

    public function destroy(AsetPerbaikan $asetPerbaikan)
    {
        $asetPerbaikan->delete();

        return response()->json(['message' => 'Riwayat perbaikan berhasil dihapus.']);
    }
}