<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\AsetPemakai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsetPemakaiController extends Controller
{
    /**
     * POST /api/aset/{aset}/pinjam
     * Karyawan request pinjam aset. Butuh approval admin sebelum resmi jadi pemakai.
     */
    public function requestPinjam(Request $request, Aset $aset)
    {
        $user = $request->user();
        $pekerjaId = $user->pekerja?->id;

        if (!$pekerjaId) {
            return response()->json(['message' => 'Akun kamu belum terhubung ke data pekerja.'], 422);
        }

        if ($aset->status !== 'tersedia') {
            return response()->json(['message' => 'Aset ini sedang tidak tersedia untuk dipinjam.'], 422);
        }

        if ($aset->pemakai()->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Sudah ada permintaan pinjam yang masih menunggu persetujuan untuk aset ini.'], 422);
        }

        $validated = $request->validate([
            'catatan_penerimaan' => 'nullable|string',
        ]);

        $pemakai = AsetPemakai::create([
            'aset_id' => $aset->id,
            'pekerja_id' => $pekerjaId,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'catatan_penerimaan' => $validated['catatan_penerimaan'] ?? null,
        ]);

        return response()->json($pemakai->load('pekerja.user'), 201);
    }

    /**
     * GET /api/aset-pemakai/pending
     * Admin: daftar request pinjam yang menunggu persetujuan.
     */
    public function pending()
    {
        $list = AsetPemakai::with(['aset', 'pekerja.user'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($list);
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/setujui
     * Admin approve request pinjam -> aset resmi jadi 'dipakai'.
     */
    public function setujui(Request $request, AsetPemakai $asetPemakai)
    {
        if ($asetPemakai->status !== 'pending') {
            return response()->json(['message' => 'Request ini sudah diproses sebelumnya.'], 422);
        }

        $validated = $request->validate([
            'nomor_penerimaan' => 'nullable|string',
            'tanggal_penerimaan' => 'nullable|date',
        ]);

        DB::transaction(function () use ($asetPemakai, $validated) {
            $asetPemakai->update([
                'status' => 'disetujui',
                'nomor_penerimaan' => $validated['nomor_penerimaan'] ?? $asetPemakai->nomor_penerimaan,
                'tanggal_penerimaan' => $validated['tanggal_penerimaan'] ?? now()->toDateString(),
            ]);

            $asetPemakai->aset()->update(['status' => 'dipakai']);
        });

        return response()->json($asetPemakai->load('pekerja.user', 'aset'));
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/tolak
     * Admin tolak request pinjam -> aset tetap 'tersedia'.
     */
    public function tolak(Request $request, AsetPemakai $asetPemakai)
    {
        if ($asetPemakai->status !== 'pending') {
            return response()->json(['message' => 'Request ini sudah diproses sebelumnya.'], 422);
        }

        $validated = $request->validate([
            'catatan_penolakan' => 'nullable|string',
        ]);

        $asetPemakai->update([
            'status' => 'ditolak',
            'catatan_penolakan' => $validated['catatan_penolakan'] ?? null,
        ]);

        return response()->json($asetPemakai->load('pekerja.user', 'aset'));
    }
}