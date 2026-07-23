<?php

namespace App\Http\Controllers;

use App\Models\Pekerja;
use App\Models\Absensi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AbsensiBaruDicatat;

class AbsensiController extends Controller
{
    // GET /api/absensi/karyawan — ADMIN ONLY (monitoring semua karyawan)
    public function karyawan(Request $request)
    {
        return response()->json(
            Pekerja::with('user', 'departemen', 'jabatan')->get()
        );
    }

    // GET /api/absensi/hari-ini — ADMIN ONLY
    public function hariIni()
    {
        Carbon::setLocale('id');
        return response()->json(
            Absensi::with('pekerja.user')
                ->where('tanggal', Carbon::today()->toDateString())
                ->get()
        );
    }

    // GET /api/absensi/riwayat — ADMIN ONLY
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

    // BARU: GET /api/absensi/saya — dipakai role non-admin (karyawan/hr/manajer)
    // Mengembalikan data pekerja milik akun yang sedang login + status absensi hari ini.
    public function saya(Request $request)
    {
        Carbon::setLocale('id');

        $pekerja = Pekerja::with('user', 'departemen', 'jabatan')
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$pekerja) {
            return response()->json([
                'message' => 'Akun ini tidak terhubung ke data karyawan.',
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

    // BARU: resolusi target absen.
    // - Non-admin: SELALU pakai pekerja milik akun sendiri (karyawan_id dari body diabaikan).
    // - Admin: boleh override lewat 'karyawan_id' di body untuk absenkan orang lain.
    private function resolveTargetPekerja(Request $request): ?Pekerja
    {
        $user = $request->user();

        if ($user->role === 'admin' && $request->filled('karyawan_id')) {
            return Pekerja::find($request->input('karyawan_id'));
        }

        return Pekerja::where('user_id', $user->id)->first();
    }

    // POST /api/absensi/scan — sekarang berbasis akun login, bukan QR.
    public function scan(Request $request)
    {
        Carbon::setLocale('id');
        $request->validate([
            'photo'               => 'required|image|max:5120',
            'latitude'            => 'required|numeric|between:-90,90',
            'longitude'           => 'required|numeric|between:-180,180',
            'face_verified'       => 'required|boolean',
            'face_match_distance' => 'nullable|numeric',
            'karyawan_id'         => 'nullable|integer', // BARU: hanya dipakai kalau requester admin
        ]);

        $pekerja = $this->resolveTargetPekerja($request);

        if (!$pekerja) {
            return response()->json(['message' => 'Data karyawan tidak ditemukan untuk akun ini.'], 404);
        }

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

        // validasi jarak ke kantor
        $officeLat = (float) config('absensi.office_lat');
        $officeLng = (float) config('absensi.office_lng');
        $maxRadius = (float) config('absensi.radius');

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

            // TAMBAH: notif ke admin & hr tiap ada karyawan yang absen masuk
            // PENTING: try-catch supaya absen yang SUDAH berhasil tercatat di atas
            // tidak ikut gagal kalau pengiriman notifikasi bermasalah.
            try {
                Notification::send(
                    User::whereIn('role', ['admin', 'hr'])->get(),
                    new AbsensiBaruDicatat($absensi)
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Gagal mengirim notifikasi absensi baru', [
                    'absensi_id' => $absensi->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

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
                'photo_path' => $photoPath,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'distance_from_office' => $distance,
                'face_verified' => true,
                'face_match_distance' => $request->face_match_distance,
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

    // POST /api/absensi/daftar-wajah — self by default, admin bisa override via karyawan_id
    public function daftarWajah(Request $request)
    {
        $request->validate([
            'descriptor'   => 'required|array|size:128',
            'descriptor.*' => 'numeric',
            'karyawan_id'  => 'nullable|integer', // BARU: hanya dipakai kalau requester admin
        ]);

        $pekerja = $this->resolveTargetPekerja($request);

        if (!$pekerja) {
            return response()->json(['message' => 'Data karyawan tidak ditemukan untuk akun ini.'], 404);
        }

        $pekerja->update(['face_descriptor' => $request->descriptor]);

        return response()->json([
            'message' => 'Wajah berhasil didaftarkan.',
            'pekerja' => $pekerja->load('user', 'departemen', 'jabatan'),
        ]);
    }

    private function hitungJarak($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}