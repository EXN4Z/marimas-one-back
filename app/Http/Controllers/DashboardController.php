<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pekerja;
use App\Models\MutasiBarang;
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
    // BARU: kalau yang akses role 'cabang', data di-scope cuma ke izin milik
    // karyawan yang lokasi_kantor_id-nya sama dengan lokasi_kantor_id akun
    // cabang yang login (bukan izin dari SEMUA cabang).
    public function analisisIzin()
    {
        $query = PengajuanIzin::query();
        $this->scopeQueryKeCabang($query, 'karyawan.pekerja');

        return response()->json([
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'disetujui' => (clone $query)->where('status', 'disetujui')->count(),
            'ditolak' => (clone $query)->where('status', 'ditolak')->count(),
        ]);
    }

    public function topKaryawan()
    {
        // UBAH: sumbernya sekarang pengajuan_izin, bukan pengajuan_cuti
        $query = PengajuanIzin::select(
                'users.name as nama',
                DB::raw('COUNT(pengajuan_izin.id) as jumlah')
            )
            ->join('users', 'pengajuan_izin.karyawan_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5);

        // BARU: scope ke cabang kalau yang login akun cabang.
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->join('pekerja', 'pekerja.user_id', '=', 'users.id')
                ->where('pekerja.lokasi_kantor_id', $user->lokasi_kantor_id);
        }

        return response()->json($query->get());
    }
    public function KaryawanPerDepart()
    {
        $user = Auth::user();

        $query = Pekerja::join('departemen', 'pekerja.departemen_id', '=', 'departemen.id')
            ->select(
                'departemen.nama as departemen',
                DB::raw('COUNT(pekerja.id) as jumlah')
            );

        // BARU: scope ke cabang kalau yang login akun cabang.
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->where('pekerja.lokasi_kantor_id', $user->lokasi_kantor_id);
        }

        $karyawan = $query->groupBy('departemen.nama')->get();

        $maxJumlah = $karyawan->max('jumlah') ?: 1;

        $karyawan = $karyawan->map(function ($item) use ($maxJumlah) {
            $item->percent = round(($item->jumlah / $maxJumlah) * 100);
            return $item;
        });

        return response()->json($karyawan);
    }
    // TAMBAH: kehadiran real 7 hari terakhir (bukan data contoh)
    public function kehadiranMingguan()
    {
        Carbon::setLocale('id');

        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate   = Carbon::now()->endOfDay();

        $user = Auth::user();
        $isCabang = $user && $user->role === 'cabang' && $user->lokasi_kantor_id;

        $pekerjaQuery = Pekerja::query();
        $absensiQuery = Absensi::whereBetween('tanggal', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->whereIn('status', ['tepat_waktu', 'telat']);

        // BARU: scope total pekerja & absensi ke cabang kalau akun cabang yang login.
        if ($isCabang) {
            $pekerjaQuery->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            $pekerjaIds = (clone $pekerjaQuery)->pluck('id');
            $absensiQuery->whereIn('karyawan_id', $pekerjaIds);
        }

        $totalPekerja = $pekerjaQuery->count();

        $absensiPerHari = $absensiQuery
            ->select('tanggal', DB::raw('COUNT(DISTINCT karyawan_id) as jumlah'))
            ->groupBy('tanggal')
            ->pluck('jumlah', 'tanggal');

        $hasil = [];
        for ($i = 6; $i >= 0; $i--) {
            $tanggal = Carbon::now()->subDays($i);
            $key = $tanggal->toDateString();

            $hasil[] = [
                'day'     => $tanggal->translatedFormat('D'),
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
    public function bebanKerja()
    {
        $today = Carbon::today()->toDateString();
        $user = Auth::user();
        $isCabang = $user && $user->role === 'cabang' && $user->lokasi_kantor_id;

        // BARU: hitung jumlah pekerja per departemen di-scope ke cabang.
        $departemenQuery = Departemen::query();
        if ($isCabang) {
            $departemenQuery->withCount(['pekerja' => function ($q) use ($user) {
                $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            }]);
        } else {
            $departemenQuery->withCount('pekerja');
        }
        $departemenList = $departemenQuery->get();

        $absensiHariIniQuery = Absensi::where('tanggal', $today)
            ->whereIn('status', ['tepat_waktu', 'telat']);

        if ($isCabang) {
            $pekerjaIdsCabang = Pekerja::where('lokasi_kantor_id', $user->lokasi_kantor_id)->pluck('id');
            $absensiHariIniQuery->whereIn('karyawan_id', $pekerjaIdsCabang);
        }

        $pekerjaIdHadirHariIni = $absensiHariIniQuery->pluck('karyawan_id')->unique();

        $hasil = $departemenList->map(function ($dept) use ($pekerjaIdHadirHariIni, $isCabang, $user) {
            $total = $dept->pekerja_count;

            $hadirQuery = Pekerja::where('departemen_id', $dept->id)
                ->whereIn('id', $pekerjaIdHadirHariIni);

            if ($isCabang) {
                $hadirQuery->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            }

            $hadir = $hadirQuery->count();
            $tidakHadir = max($total - $hadir, 0);

            $bebanPercent = $hadir > 0
                ? round(($tidakHadir / $hadir) * 100)
                : ($total > 0 ? 100 : 0);

            return [
                'departemen'    => $dept->nama,
                'total'         => $total,
                'hadir'         => $hadir,
                'tidak_hadir'   => $tidakHadir,
                'beban_percent' => (int) $bebanPercent,
            ];
        });

        return response()->json($hasil->sortByDesc('beban_percent')->values());
    }

    public function mutasiBarang() 
    {
        Carbon::setLocale('id');
        $hasil = [];

        for ($i = 5; $i >= 0; $i--) {
            $bulan = Carbon::now()->subMonths($i);

            $masuk = MutasiBarang::where('tipe', 'masuk')
                    ->whereMonth('created_at', $bulan->month)
                    ->whereYear('created_at', $bulan->year)
                    ->sum('jumlah');

            $keluar = MutasiBarang::where('tipe', 'keluar')
                    ->whereMonth('created_at', $bulan->month)
                    ->whereYear('created_at', $bulan->year)
                    ->sum('jumlah');

            $hasil[] = [
                'bulan' => $bulan->translatedFormat('M'),
                'jumlah_masuk' => $masuk,
                'jumlah_keluar' => $keluar,
            ];
        }

        return response()->json($hasil);
    }
    public function totalBarang() {
        $update_masuk = MutasiBarang::where('tipe', 'masuk')->max('updated_at');
        $update_keluar = MutasiBarang::where('tipe', 'keluar')->max('updated_at');
        $masuk = MutasiBarang::where('tipe', 'masuk')->sum('jumlah');
        $keluar = MutasiBarang::where('tipe', 'keluar')->sum('jumlah');
        return response()->json([
            'jumlah_masuk' => $masuk,
            'jumlah_keluar' => $keluar,
            'update_masuk' => $update_masuk,
            'update_keluar' => $update_keluar,
        ]);
    }
    public function topKehadiran()
    {
        $query = Absensi::select(
                'users.name as nama',
                DB::raw('COUNT(absensis.id) as jumlah')
            )
            ->join('pekerja', 'absensis.karyawan_id', '=', 'pekerja.id')
            ->join('users', 'pekerja.user_id', '=', 'users.id')
            ->where('absensis.status', 'tepat_waktu')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5);

        // BARU: scope ke cabang kalau yang login akun cabang.
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->where('pekerja.lokasi_kantor_id', $user->lokasi_kantor_id);
        }

        return response()->json($query->get());
    }
    public function grafikPengajuan() {
        Carbon::setLocale('id');
        $hasil = [];

        for ($i = 5; $i >= 0; $i--) {
            $bulan = Carbon::now()->subMonths($i);

            // UBAH: dari PengajuanCuti ke PengajuanIzin
            $jumlah = PengajuanIzin::whereMonth('tanggal_mulai', $bulan->month)
                        ->whereYear('tanggal_mulai', $bulan->year)
                        ->count();

            $hasil[] = [
                'bulan' => $bulan->translatedFormat('M'),
                'pengajuan' => $jumlah,
            ];
        }

        return response()->json($hasil);
    }
    public function keuanganPerBulan() {
        $totalPengeluaran = MutasiBarang::with('barang')
                        ->where('tipe', 'keluar')
                        ->get()
                        ->sum(function ($item) {
                            return $item->jumlah * $item->barang->harga;
                        });
        $totalPemasukan = MutasiBarang::with('barang')
                        ->where('tipe', 'masuk')
                        ->get()
                        ->sum(function ($item) {
                            return $item->jumlah * $item->barang->harga;
                        });
        return response()->json([
            'totalPengeluaran' => $totalPengeluaran,
            'totalPemasukan' => $totalPemasukan,
        ]);
    }
    public function totalKeuangan() 
    {
        Carbon::setLocale('id');
        $hasil = [];

        for ($i = 5; $i >= 0; $i--) {
            $bulan = Carbon::now()->subMonths($i);

            $pengeluaran = MutasiBarang::with('barang')
                ->where('tipe', 'masuk')
                ->whereMonth('created_at', $bulan->month)
                ->whereYear('created_at', $bulan->year)
                ->get()
                ->sum(fn ($item) => $item->jumlah * $item->barang->harga);

            $pemasukan = MutasiBarang::with('barang')
                ->where('tipe', 'keluar')
                ->whereMonth('created_at', $bulan->month)
                ->whereYear('created_at', $bulan->year)
                ->get()
                ->sum(fn ($item) => $item->jumlah * $item->barang->harga);

            $hasil[] = [
                'bulan' => $bulan->translatedFormat('M'),
                'pemasukan' => $pemasukan,
                'pengeluaran' => $pengeluaran,
            ];
        }

        return response()->json($hasil);
    }
    public function debugKeuangan() {
        $data = MutasiBarang::with('barang')->get()->map(function ($m) {
            return [
                'id' => $m->id,
                'tipe' => $m->tipe,
                'jumlah' => $m->jumlah,
                'barang_id' => $m->barang_id,
                'barang_ada' => $m->barang ? true : false,
                'harga' => $m->barang->harga ?? 'BARANG NULL',
                'created_at' => $m->created_at,
            ];
        });

        return response()->json($data);
    }

    // UBAH: statsCard() sekarang punya 2 jalur.
    // - role selain 'cabang': PERSIS seperti sebelumnya, data pribadi si user
    //   yang login (dicari lewat Pekerja::where('user_id', ...)).
    // - role 'cabang': akun cabang BUKAN baris di tabel pekerja (dia gak
    //   punya data absensi/izin pribadi), jadi cabang dapet jalur terpisah:
    //   agregat dari SEMUA karyawan yang lokasi_kantor_id-nya sama dengan
    //   lokasi_kantor_id akun cabang tsb. Ini yang bikin "Kehadiran Bulan
    //   Ini" dulu selalu 0 di akun cabang -- dia kena jalur pertama padahal
    //   harusnya kena jalur kedua ini.
    public function statsCard()
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->role === 'cabang') {
            return $this->statsCardCabang($user);
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

    // BARU: versi statsCard khusus akun cabang -- agregat, bukan data pribadi.
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

    // BARU: helper buat nge-scope query manapun (PengajuanIzin, dst) ke
    // lokasi_kantor_id akun cabang yang login, lewat relasi nested sampai ke
    // tabel pekerja. Kalau yang login bukan role 'cabang' (atau lokasi_kantor_id
    // belum diset), query dibiarin apa adanya (gak difilter).
    private function scopeQueryKeCabang(Builder $query, string $relasiKePekerja): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'cabang' || ! $user->lokasi_kantor_id) {
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