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
        return response()->json(
            Pekerja::with('user', 'departemen', 'jabatan')->get()
        );
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

    // GET /api/karyawan/kode/{kode} — preview data sebelum konfirmasi
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

        $absensiHariIni = Absensi::where('karyawan_id', $pekerja->id)
            ->whereDate('tanggal', Carbon::today())
            ->first();

        return response()->json([
            'pekerja' => $pekerja,
            'absensi_hari_ini' => $absensiHariIni,
        ]);
    }

    // POST /api/absensi/scan — satu endpoint, otomatis nentuin masuk/pulang
    public function scan(Request $request)
    {
        $request->validate([
            'qr_code' => 'required|string',
        ]);

        $pekerja = Pekerja::where('qr_code', $request->qr_code)->first();

        if (!$pekerja) {
            return response()->json(['message' => 'QR tidak dikenali.'], 404);
        }

        $absensi = Absensi::where('karyawan_id', $pekerja->id)
            ->whereDate('tanggal', Carbon::today())
            ->first();

        // Kasus 1: belum ada record hari ini -> absen masuk
        if (!$absensi) {
            $jamMasukStandar = Carbon::parse(config('absensi.jam_masuk_standar'));
            $toleransi = config('absensi.toleransi_menit');
            $batasTelat = $jamMasukStandar->copy()->addMinutes($toleransi);

            $sekarang = Carbon::now();
            $status = $sekarang->format('H:i') > $batasTelat->format('H:i') ? 'telat' : 'tepat_waktu';

            $absensi = Absensi::create([
                'karyawan_id' => $pekerja->id,
                'tanggal' => Carbon::today(),
                'jam_masuk' => $sekarang->format('H:i:s'),
                'status' => $status,
            ]);

            return response()->json([
                'tipe' => 'masuk',
                'pekerja' => $pekerja->load('user'),
                'absensi' => $absensi,
                'message' => "Absen masuk berhasil ({$status}).",
            ]);
        }

        // Kasus 2: udah masuk, belum pulang -> absen pulang
        if (!$absensi->jam_pulang) {
            $sekarang = Carbon::now();
            $jamPulangStandar = Carbon::parse(config('absensi.jam_pulang_standar'));

            $statusPulang = $sekarang->format('H:i') < $jamPulangStandar->format('H:i')
                ? 'pulang_cepat'
                : 'pulang_normal';

            $absensi->update([
                'jam_pulang' => $sekarang->format('H:i:s'),
                'status_pulang' => $statusPulang,
            ]);

            return response()->json([
                'tipe' => 'pulang',
                'pekerja' => $pekerja->load('user'),
                'absensi' => $absensi,
                'message' => $statusPulang === 'pulang_cepat'
                    ? 'Absen pulang berhasil (pulang cepat).'
                    : 'Absen pulang berhasil.',
            ]);
        }

        // Kasus 3: udah lengkap dua-duanya
        return response()->json([
            'tipe' => 'sudah_lengkap',
            'pekerja' => $pekerja->load('user'),
            'absensi' => $absensi,
            'message' => 'Karyawan ini sudah absen masuk & pulang hari ini.',
        ]);
    }
}