<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Absensi;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AbsensiController extends Controller
{
    // GET /api/absensi/karyawan
    public function karyawan(Request $request)
    {
        $query = User::query();

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    // GET /api/absensi/hari-ini
    public function hariIni()
    {
        return response()->json(
            Absensi::with('karyawan')
                ->whereDate('tanggal', Carbon::today())
                ->get()
        );
    }

    // GET /api/absensi/riwayat
    public function riwayat(Request $request)
    {
        $limit = $request->get('limit', 10);

        return response()->json(
            Absensi::with('karyawan')
                ->latest()
                ->limit($limit)
                ->get()
        );
    }

    // POST /api/absensi/masuk
    public function absenMasuk(Request $request)
    {
        $request->validate([
            'karyawan_id' => 'required|exists:users,id',
        ]);

        $absensi = Absensi::firstOrCreate(
            [
                'karyawan_id' => $request->karyawan_id,
                'tanggal' => Carbon::today(),
            ],
            [
                'jam_masuk' => now(),
                'status' => 'tepat_waktu',
            ]
        );

        return response()->json([
            'karyawan' => $absensi->karyawan,
            'absensi' => $absensi,
        ]);
    }

    // POST /api/absensi/pulang
    public function absenPulang(Request $request)
    {
        $request->validate([
            'karyawan_id' => 'required|exists:users,id',
        ]);

        $absensi = Absensi::where('karyawan_id', $request->karyawan_id)
            ->whereDate('tanggal', Carbon::today())
            ->firstOrFail();

        $absensi->update([
            'jam_pulang' => now(),
        ]);

        return response()->json([
            'karyawan' => $absensi->karyawan,
            'absensi' => $absensi,
        ]);
    }
    public function getByKode(String $kode)
    {
        $karyawan = User::where('kode_karyawan', $kode)->first();

        if (!$karyawan) {
            return response()->json([
                'message' => 'Karyawan tidak ditemukan.'
            ], 404);
        }

        return response()->json($karyawan);
    }
}