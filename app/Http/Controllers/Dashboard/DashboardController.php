<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pekerja;
use Carbon\Carbon;
use App\Models\PengajuanIzin;
use App\Models\Absensi;
use App\Models\Ticket;
use App\Models\Departemen;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
    // UBAH: dari analisisCuti() ke analisisIzin() -- fitur cuti dihapus, semua ditangani lewat izin
    // UBAH: sekarang di-scope ke cabang kalau yang login akun cabang.
    public function analisisIzin()
    {
        $query = PengajuanIzin::query();
        $this->scopeQueryKeCabang($query, 'karyawan.pekerja');

        return response()->json([
            'total'     => (clone $query)->count(),
            'pending'   => (clone $query)->where('status', 'pending')->count(),
            'disetujui' => (clone $query)->where('status', 'disetujui')->count(),
            'ditolak'   => (clone $query)->where('status', 'ditolak')->count(),
        ]);
    }

    public function topKaryawan()
    {
        // UBAH: sumbernya sekarang pengajuan_izin, bukan pengajuan_cuti
        // UBAH: di-scope ke cabang.
        $query = PengajuanIzin::select(
                'users.name as nama',
                DB::raw('COUNT(pengajuan_izin.id) as jumlah')
            )
            ->join('users', 'pengajuan_izin.karyawan_id', '=', 'users.id');

        $this->scopeQueryKeCabang($query, 'karyawan.pekerja');

        $topKaryawan = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return response()->json($topKaryawan);
    }

    public function KaryawanPerDepart()
    {
        // UBAH: di-scope ke cabang. Karena base query-nya langsung tabel
        // pekerja (bukan lewat relasi), pakai relasiKePekerja = '' supaya
        // helper langsung filter kolom pekerja.lokasi_kantor_id.
        $query = Pekerja::join('departemen', 'pekerja.departemen_id', '=', 'departemen.id')
            ->select(
                'departemen.nama as departemen',
                DB::raw('COUNT(pekerja.id) as jumlah')
            );

        $this->scopeQueryKeCabang($query, '');

        $karyawan = $query->groupBy('departemen.nama')->get();

        $maxJumlah = $karyawan->max('jumlah') ?: 1; // fallback 1 biar ga divide by zero

        $karyawan = $karyawan->map(function ($item) use ($maxJumlah) {
            $item->percent = round(($item->jumlah / $maxJumlah) * 100);
            return $item;
        });

        return response()->json($karyawan);
    }

    // TAMBAH: kehadiran real 7 hari terakhir (bukan data contoh)
    // UBAH: di-scope ke cabang (total pekerja & absensi cuma yang di lokasi kantor cabang itu).
    public function kehadiranMingguan()
    {
        Carbon::setLocale('id');

        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate   = Carbon::now()->endOfDay();

        $pekerjaQuery = Pekerja::query();
        $this->scopeQueryKeCabang($pekerjaQuery, '');
        $totalPekerja = $pekerjaQuery->count();

        // hadir = ada absensi hari itu dengan status tepat_waktu ATAU telat
        $absensiQuery = Absensi::whereBetween('tanggal', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->whereIn('status', ['tepat_waktu', 'telat']);

        $this->scopeQueryKeCabang($absensiQuery, 'pekerja');

        $absensiPerHari = $absensiQuery
            ->select('tanggal', DB::raw('COUNT(DISTINCT karyawan_id) as jumlah'))
            ->groupBy('tanggal')
            ->pluck('jumlah', 'tanggal');

        $hasil = [];
        for ($i = 6; $i >= 0; $i--) {
            $tanggal = Carbon::now()->subDays($i);
            $key = $tanggal->toDateString();

            $hasil[] = [
                'day'     => $tanggal->translatedFormat('D'), // Sen, Sel, Rab, ...
                'tanggal' => $key,
                'hadir'   => (int) ($absensiPerHari[$key] ?? 0),
                'target'  => $totalPekerja,
            ];
        }

        return response()->json($hasil);
    }

    // UBAH: sebelumnya "tidak hadir" cuma dihitung dari izin yang disetujui &
    // aktif hari ini — di kondisi normal itu hampir selalu 0 orang, jadi
    // beban_percent selalu 0% buat semua departemen tiap hari (keliatan statis).
    // Sekarang "hadir" dihitung dari absensi check-in beneran hari ini, jadi
    // angkanya ikut berubah sesuai siapa yang udah absen masuk.
    // UBAH LAGI: sekarang di-scope ke cabang -- baik jumlah pekerja per
    // departemen maupun status hadirnya cuma diitung dari pekerja di lokasi
    // kantor cabang yang login.
    public function bebanKerja()
    {
        $today = Carbon::today()->toDateString();

        $departemenList = Departemen::withCount(['pekerja' => function ($q) {
                $this->scopeQueryKeCabang($q, '');
            }])
            ->get();

        // id Pekerja (bukan users.id) yang sudah absen masuk hari ini
        $absensiQuery = Absensi::where('tanggal', $today)
            ->whereIn('status', ['tepat_waktu', 'telat']);

        $this->scopeQueryKeCabang($absensiQuery, 'pekerja');

        $pekerjaIdHadirHariIni = $absensiQuery->pluck('karyawan_id')->unique();

        $hasil = $departemenList->map(function ($dept) use ($pekerjaIdHadirHariIni) {
            $total = $dept->pekerja_count;

            $hadirQuery = Pekerja::where('departemen_id', $dept->id)
                ->whereIn('id', $pekerjaIdHadirHariIni);

            $this->scopeQueryKeCabang($hadirQuery, '');

            $hadir = $hadirQuery->count();

            $tidakHadir = max($total - $hadir, 0);

            if ($hadir > 0) {
                $bebanPercent = round(($tidakHadir / $hadir) * 100);
            } else {
                $bebanPercent = $total > 0 ? 100 : 0; // belum ada yang absen sama sekali = kritis
            }

            return [
                'departemen'   => $dept->nama,
                'total'        => $total,
                'hadir'        => $hadir,
                'tidak_hadir'  => $tidakHadir,
                'beban_percent' => (int) $bebanPercent,
            ];
        });

        return response()->json($hasil->sortByDesc('beban_percent')->values());
    }

    public function topKehadiran()
    {
        // UBAH: di-scope ke cabang.
        $query = Absensi::select(
                'users.name as nama',
                DB::raw('COUNT(absensis.id) as jumlah')
            )
            ->join('pekerja', 'absensis.karyawan_id', '=', 'pekerja.id')
            ->join('users', 'pekerja.user_id', '=', 'users.id')
            ->where('absensis.status', 'tepat_waktu');

        $this->scopeQueryKeCabang($query, 'pekerja');

        $topKehadiran = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return response()->json($topKehadiran);
    }

    public function grafikPengajuan()
    {
        Carbon::setLocale('id');
        $hasil = [];

        for ($i = 5; $i >= 0; $i--) {
            $bulan = Carbon::now()->subMonths($i);

            $query = PengajuanIzin::whereMonth('tanggal_mulai', $bulan->month)
                        ->whereYear('tanggal_mulai', $bulan->year);

            // BARU: scope ke cabang kalau yang login akun cabang.
            $this->scopeQueryKeCabang($query, 'karyawan.pekerja');

            $hasil[] = [
                'bulan' => $bulan->translatedFormat('M'),
                'pengajuan' => $query->count(),
            ];
        }

        return response()->json($hasil);
    }

    public function statsCard()
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Akun cabang sudah punya jalur agregat sendiri (statsCardCabang),
        // jadi tidak perlu scopeQueryKeCabang di sini.
        if ($user->role === 'cabang') {
            return $this->statsCardCabang($user);
        }

        // BARU: akun admin/hr/manajer umumnya TIDAK punya baris Pekerja
        // sendiri (mereka bukan karyawan operasional yang absen/ambil izin),
        // jadi query "data pribadi" di bawah selalu balikin null -> 0 semua.
        // Untuk role 'admin' secara khusus, tampilkan agregat SELURUH
        // perusahaan (mirip statsCardCabang tapi tanpa filter lokasi kantor).
        if ($user->role === 'admin') {
            return $this->statsCardAdmin();
        }

        $pekerja = Pekerja::where('user_id', $user->id)->first();

        if (! $pekerja) {
            return response()->json([
                'kehadiran' => ['value' => 0, 'trend' => 'Belum ada data'],
                'izin' => ['value' => 0, 'trend' => 'Belum ada data'],
                'izinAktif' => ['value' => 0, 'trend' => 'Belum ada data'],
                'ticket' => ['value' => 0, 'trend' => 'Belum ada data'],
            ]);
        }

        $izin = PengajuanIzin::where('karyawan_id', $pekerja->id);
        $absensi = Absensi::where('karyawan_id', $pekerja->id);
        $ticket = Ticket::where('user_id', $user->id);

        return response()->json([
            'kehadiran' => [
                'value' => (clone $absensi)->whereIn('status', ['tepat_waktu', 'telat'])->count(),
                'trend' => $this->getTrend((clone $absensi)->where('status', 'tepat_waktu')),
            ],
            'izin' => [
                'value' => (clone $izin)->where('status', 'pending')->count(),
                'trend' => $this->getTrend($izin),
            ],
            'izinAktif' => [
                // UBAH: sebelumnya format "X hari" dari izin disetujui terakhir.
                // Sekarang TOTAL seluruh pengajuan izin milik karyawan ini (semua status).
                'value' => (clone $izin)->count(),
                'trend' => $this->getTrend($izin),
            ],
            'ticket' => [
                // UBAH: sebelumnya cuma hitung status 'diproses'.
                // Sekarang TOTAL seluruh ticket yang pernah diajukan karyawan ini (semua status).
                'value' => (clone $ticket)->count(),
                'trend' => $this->getTrend($ticket)
            ]
        ]);
    }

    // BARU: versi statsCard khusus akun admin -- agregat SELURUH perusahaan,
    // tanpa filter lokasi kantor (beda dari statsCardCabang yang di-scope
    // ke satu lokasi_kantor_id -- admin sengaja TIDAK di-scope ke lokasi
    // karena akun admin biasanya lokasi_kantor_id-nya null / tidak terikat
    // satu cabang tertentu). Dipanggil karena admin biasanya tidak punya
    // baris Pekerja sendiri, jadi jalur data-pribadi di atas tidak relevan
    // untuknya.
    // UBAH: kehadiran dihitung per BULAN INI (bukan hari ini), konsisten
    // sama cara statsCardCabang menghitung "Kehadiran Bulan Ini".
    private function statsCardAdmin()
    {
        // "Kehadiran Bulan Ini" = total kehadiran (tepat_waktu/telat) SEMUA
        // karyawan, seluruh perusahaan, bulan berjalan.
        $absensiBulanIni = Absensi::whereIn('status', ['tepat_waktu', 'telat'])
            ->whereMonth('tanggal', now()->month)
            ->whereYear('tanggal', now()->year);

        $izin = PengajuanIzin::query();
        $ticket = Ticket::query();

        return response()->json([
            'kehadiran' => [
                'value' => (clone $absensiBulanIni)->count(),
                'trend' => $this->getTrend($absensiBulanIni),
            ],
            'izin' => [
                'value' => (clone $izin)->where('status', 'pending')->count(),
                'trend' => $this->getTrend($izin),
            ],
            'izinAktif' => [
                // Sama seperti statsCardCabang: buat agregat banyak
                // karyawan, "X hari" gak masuk akal, jadi ditampilin
                // sebagai JUMLAH izin yang lagi disetujui/aktif.
                'value' => (clone $izin)->where('status', 'disetujui')->count(),
                'trend' => $this->getTrend($izin),
            ],
            'ticket' => [
                'value' => (clone $ticket)->count(),
                'trend' => $this->getTrend($ticket),
            ],
        ]);
    }

    // BARU: versi statsCard khusus akun cabang -- agregat, bukan data pribadi.
    // Method ini sudah scoped secara manual lewat $pekerjaIds (filter
    // lokasi_kantor_id), jadi tidak pakai scopeQueryKeCabang lagi di sini.
    private function statsCardCabang($user)
    {
        if (! $user->lokasi_kantor_id) {
            return response()->json([
                'kehadiran' => ['value' => 0, 'trend' => 'Akun cabang ini belum diset lokasi kantornya'],
                'izin' => ['value' => 0, 'trend' => 'Belum ada data'],
                'izinAktif' => ['value' => 0, 'trend' => 'Belum ada data'],
                'ticket' => ['value' => 0, 'trend' => 'Tidak berlaku untuk akun cabang'],
            ]);
        }

        $pekerjaIds = Pekerja::where('lokasi_kantor_id', $user->lokasi_kantor_id)->pluck('id');

        // "Kehadiran Bulan Ini" = total kehadiran (tepat_waktu) SEMUA karyawan
        // di cabang ini, bulan berjalan.
        $absensiBulanIni = Absensi::whereIn('karyawan_id', $pekerjaIds)
            ->whereIn('status', ['tepat_waktu', 'telat'])
            ->whereMonth('tanggal', now()->month)
            ->whereYear('tanggal', now()->year);

        $izinCabang = PengajuanIzin::whereIn('karyawan_id', function ($q) use ($pekerjaIds) {
            $q->select('user_id')->from('pekerja')->whereIn('id', $pekerjaIds);
        });

        return response()->json([
            'kehadiran' => [
                'value' => (clone $absensiBulanIni)->count(),
                'trend' => $this->getTrend($absensiBulanIni),
            ],
            'izin' => [
                'value' => (clone $izinCabang)->where('status', 'pending')->count(),
                'trend' => $this->getTrend($izinCabang),
            ],
            'izinAktif' => [
                // Beda dari jalur pribadi (yang formatnya "X hari"): buat
                // agregat banyak karyawan, "X hari" gak masuk akal, jadi
                // ditampilin sebagai JUMLAH izin yang lagi disetujui/aktif.
                'value' => (clone $izinCabang)->where('status', 'disetujui')->count(),
                'trend' => $this->getTrend($izinCabang),
            ],
            'ticket' => [
                // Ticket gak ada relasi ke lokasi_kantor sama sekali di model
                // yang ada sekarang, jadi sengaja gak ditampilin per-cabang.
                'value' => 0,
                'trend' => 'Tidak berlaku untuk akun cabang',
            ],
        ]);
    }

    // BARU: helper buat nge-scope query manapun (PengajuanIzin, Absensi, dst)
    // ke lokasi_kantor_id akun cabang yang login.
    //
    // - $relasiKePekerja diisi nama relasi (bisa nested, misal 'karyawan.pekerja')
    //   kalau base model query-nya BUKAN Pekerja -- helper akan whereHas ke
    //   relasi itu lalu filter lokasi_kantor_id di ujungnya.
    // - $relasiKePekerja diisi string kosong '' kalau base model query-nya
    //   SUDAH Pekerja itu sendiri (misal Pekerja::query(), atau builder relasi
    //   'pekerja' dari withCount/with) -- helper filter langsung kolom
    //   pekerja.lokasi_kantor_id tanpa whereHas.
    //
    // Kalau yang login bukan role 'cabang' (atau lokasi_kantor_id belum
    // diset), query dibiarin apa adanya (gak difilter).
    private function scopeQueryKeCabang(Builder $query, string $relasiKePekerja): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'cabang' || ! $user->lokasi_kantor_id) {
            return;
        }

        if ($relasiKePekerja === '') {
            $query->where('pekerja.lokasi_kantor_id', $user->lokasi_kantor_id);
            return;
        }

        $query->whereHas($relasiKePekerja, function ($q) use ($user) {
            $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
        });
    }

    private function getTrend(Builder $query): string
    {
        $updatedAt = (clone $query)->max('updated_at');

        return $updatedAt
            ? 'Update terakhir ' . Carbon::parse($updatedAt)->diffForHumans()
            : 'Belum ada data';
    }
}