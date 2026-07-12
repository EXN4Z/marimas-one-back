<?php

namespace App\Http\Controllers;

use App\Models\Pekerja;
use App\Models\Absensi;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AbsensiController extends Controller
{
    // GET /api/absensi/karyawan
    public function karyawan(Request $request)
    {
        $query = Pekerja::with('user', 'divisi', 'jabatan');
        return response()->json($query->get());
    }

    // GET /api/absensi/hari-ini
    public function hariIni()
    {
        return response()->json(
            Absensi::with('pekerja.user')
                ->whereDate('tanggal', Carbon::today())
                ->get()
        );
    }

    // GET /api/absensi/riwayat
    public function riwayat(Request $request)
    {
        $limit = $request->get('limit', 10);

        return response()->json(
            Absensi::with('pekerja.user')
                ->latest()
                ->limit($limit)
                ->get()
        );
    }

    // POST /api/absensi/masuk
    public function absenMasuk(Request $request)
    {
        $request->validate([
            'pekerja_id' => 'required|exists:pekerja,id',
        ]);

        $absensi = Absensi::firstOrCreate(
            [
                'karyawan_id' => $request->pekerja_id,
                'tanggal' => Carbon::today(),
            ],
            [
                'jam_masuk' => now(),
                'status' => 'tepat_waktu',
            ]
        );

        return response()->json([
            'pekerja' => $absensi->pekerja,
            'absensi' => $absensi,
        ]);
    }

    // POST /api/absensi/pulang
    public function absenPulang(Request $request)
    {
        $request->validate([
            'pekerja_id' => 'required|exists:pekerja,id',
        ]);

        $absensi = Absensi::where('karyawan_id', $request->pekerja_id)
            ->whereDate('tanggal', Carbon::today())
            ->firstOrFail();

        $absensi->update([
            'jam_pulang' => now(),
        ]);

        return response()->json([
            'pekerja' => $absensi->pekerja,
            'absensi' => $absensi,
        ]);
    }

    public function getByKode(string $kode)
    {
        $pekerja = Pekerja::with('user', 'divisi', 'jabatan')
            ->where('qr_code', $kode)
            ->first();

        if (!$pekerja) {
            return response()->json([
                'message' => 'Karyawan tidak ditemukan.'
            ], 404);
        }

        return response()->json($pekerja);
    }
}