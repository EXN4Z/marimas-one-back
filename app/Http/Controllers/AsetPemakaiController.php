<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\AsetPemakai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsetPemakaiController extends Controller
{
    /**
     * POST /api/aset/{aset}/pemakai
     * Assign aset ke pekerja (serah terima).
     */
    public function store(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'pekerja_id' => 'required|exists:pekerja,id',
            'nomor_penerimaan' => 'nullable|string',
            'tanggal_penerimaan' => 'required|date',
            'catatan_penerimaan' => 'nullable|string',
        ]);

        if ($aset->pemakaiSaatIni()->exists()) {
            return response()->json([
                'message' => 'Aset ini masih dipakai orang lain, kembalikan dulu sebelum diserahkan ke pekerja baru.',
            ], 422);
        }

        $pemakai = DB::transaction(function () use ($aset, $validated) {
            $pemakai = AsetPemakai::create([
                'aset_id' => $aset->id,
                ...$validated,
            ]);

            $aset->update(['status' => 'dipakai']);

            return $pemakai;
        });

        return response()->json($pemakai->load('pekerja.user'), 201);
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/kembalikan
     * Terima kembali aset dari pekerja.
     */
    public function kembalikan(Request $request, AsetPemakai $asetPemakai)
    {
        $validated = $request->validate([
            'nomor_pengembalian' => 'nullable|string',
            'tanggal_pengembalian' => 'required|date',
            'catatan_pengembalian' => 'nullable|string',
        ]);

        DB::transaction(function () use ($asetPemakai, $validated) {
            $asetPemakai->update($validated);
            $asetPemakai->aset()->update(['status' => 'tersedia']);
        });

        return response()->json($asetPemakai->load('pekerja.user'));
    }
}