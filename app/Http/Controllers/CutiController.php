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
    public function updateStatusCuti(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:disetujui,ditolak'
        ]);

        $cuti = PengajuanCuti::findOrFail($id);

        $cuti->status = $request->status;
        $cuti->save();

        return response()->json([
            'message' => 'Status berhasil diupdate',
            'data' => $cuti
        ]);
    }
    public function batalkanCuti($id)
    {
        $cuti = PengajuanCuti::findOrFail($id);

        if ($cuti->status !== 'pending') {
            return response()->json([
                'message' => 'Hanya pengajuan cuti dengan status pending yang dapat dibatalkan.'
            ], 400);
        }

        $cuti->delete();

        return response()->json([
            'message' => 'Pengajuan cuti berhasil dibatalkan.'
        ]);
    }
}
