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
        Carbon::setLocale('id');
        return response()->json(
            Absensi::with('pekerja.user')
                ->where('tanggal', Carbon::today()->toDateString())
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
        Carbon::setLocale('id');
        $pekerja = Pekerja::with('user', 'departemen', 'jabatan')
            ->where('qr_code', $kode)
            ->first();

        if (!$pekerja) {
            return response()->json([
                'message' => 'Karyawan tidak ditemukan.'
            ], 404);
        }

        $absensiHariIni = Absensi::where('karyawan_id', $pekerja->id)
            ->where('tanggal', Carbon::today()->toDateString())
            ->first();

        return response()->json([
            'pekerja' => $pekerja,
            'absensi_hari_ini' => $absensiHariIni,
        ]);
    }

    // POST /api/absensi/scan — satu endpoint, otomatis nentuin masuk/pulang
    // UBAH: sekarang wajib kirim foto wajah + koordinat GPS, divalidasi jaraknya ke kantor
    public function scan(Request $request)
    {
        Carbon::setLocale('id');
        $request->validate([
        'qr_code'            => 'required|string',
        'photo'              => 'required|image|max:5120',
        'latitude'           => 'required|numeric|between:-90,90',
        'longitude'          => 'required|numeric|between:-180,180',
        'face_verified'      => 'required|boolean', // BARU
        'face_match_distance'=> 'nullable|numeric', // BARU
        ]);

        $pekerja = Pekerja::where('qr_code', $request->qr_code)->first();

        if (!$pekerja) {
            return response()->json(['message' => 'QR tidak dikenali.'], 404);
        }

        // BARU: wajah wajib terdaftar & cocok sebelum lanjut
        if (!$pekerja->face_descriptor) {
            return response()->json([
                'message' => 'Wajah karyawan ini belum didaftarkan. Silakan daftarkan wajah terlebih dahulu.',
            ], 422);
        }

        if (!$request->boolean('face_verified')) {
            return response()->json([
                'message' => 'Verifikasi wajah gagal, wajah tidak cocok dengan data terdaftar.',
            ], 422);
        }

        // BARU: validasi jarak ke kantor sebelum lanjut apapun
        $officeLat = (float) config('absemsi.office_lat');
        $officeLng = (float) config('absemsi.office_lng');
        $maxRadius = (float) config('absemsi.radius');

        $distance = $this->hitungJarak(
            $request->latitude,
            $request->longitude,
            $officeLat,
            $officeLng
        );

        if ($distance > $maxRadius) {
            return response()->json([
                'message' => 'Anda berada di luar area kantor (' . round($distance) . ' meter dari lokasi kantor).',
            ], 422);
        }

        // BARU: simpan foto ke storage/app/public/absensi
        $photoPath = $request->file('photo')->store('absensi', 'public');

        $absensi = Absensi::where('karyawan_id', $pekerja->id)
            ->where('tanggal', Carbon::today()->toDateString())
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
                'photo_path' => $photoPath,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'distance_from_office' => $distance,
                'face_verified' => true,
                'face_match_distance' => $request->face_match_distance,
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
                // BARU: foto & GPS absen pulang menimpa yang masuk (kolom sama)
                // kalau mau simpan keduanya terpisah, tambah kolom photo_path_pulang dkk
                'photo_path' => $photoPath,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'distance_from_office' => $distance,
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

    // BARU: helper hitung jarak GPS pakai formula Haversine
    private function hitungJarak($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
    // POST /api/absensi/daftar-wajah/{kode}
    public function daftarWajah(Request $request, string $kode)
    {
        $request->validate([
            'descriptor'   => 'required|array|size:128',
            'descriptor.*' => 'numeric',
        ]);

        $pekerja = Pekerja::where('qr_code', $kode)->first();

        if (!$pekerja) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $pekerja->update(['face_descriptor' => $request->descriptor]);

        return response()->json([
            'message' => 'Wajah berhasil didaftarkan.',
            'pekerja' => $pekerja->load('user', 'departemen', 'jabatan'),
        ]);
    }
}