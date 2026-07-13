<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PengajuanCuti;

class CutiController extends Controller
{
    public function riwayatCuti(Request $request)
    {
        $limit = $request->get('limit', 10);

        return response()->json(
            PengajuanCuti::with('user')
                ->whereNotNull('tanggal_mulai')
                ->latest()
                ->limit($limit)
                ->get()
        );
    }
    public function create(Request $request)
    {
        try {
            $request->validate([
                'tanggal_mulai' => 'required|date',
                'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
                'alasan' => 'required|string|max:255',
            ]);

            $pengajuanCuti = PengajuanCuti::create([
                'karyawan_id' => $request->user()->id,
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'alasan' => $request->alasan,
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Pengajuan cuti berhasil dibuat.',
                'pengajuan_cuti' => $pengajuanCuti,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
