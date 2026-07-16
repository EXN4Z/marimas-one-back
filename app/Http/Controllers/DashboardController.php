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
    public function analisisIzin() {
        return response()->json([
            'total' => PengajuanIzin::count(),
            'pending' => PengajuanIzin::where('status', 'pending')->count(),
            'disetujui' => PengajuanIzin::where('status', 'disetujui')->count(),
            'ditolak' => PengajuanIzin::where('status', 'ditolak')->count(),
        ]);
    }
    public function topKaryawan()
    {
        // UBAH: sumbernya sekarang pengajuan_izin, bukan pengajuan_cuti
        $topKaryawan = PengajuanIzin::select(
                'users.name as nama',
                DB::raw('COUNT(pengajuan_izin.id) as jumlah')
            )
            ->join('users', 'pengajuan_izin.karyawan_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return response()->json($topKaryawan);
    }
    public function KaryawanPerDepart()
    {
        $karyawan = Pekerja::join('departemen', 'pekerja.departemen_id', '=', 'departemen.id')
            ->select(
                'departemen.nama as departemen',
                DB::raw('COUNT(pekerja.id) as jumlah')
            )
            ->groupBy('departemen.nama')
            ->get();

            $maxJumlah = $karyawan->max('jumlah') ?: 1; // fallback 1 biar ga divide by zero

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

        $totalPekerja = Pekerja::count();

        // hadir = ada absensi hari itu dengan status tepat_waktu ATAU telat
        $absensiPerHari = Absensi::whereBetween('tanggal', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->whereIn('status', ['tepat_waktu', 'telat'])
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
    public function bebanKerja()
    {
        $today = Carbon::today()->toDateString();

        $departemenList = Departemen::withCount('pekerja')->get();

        // id Pekerja (bukan users.id) yang sudah absen masuk hari ini
        $pekerjaIdHadirHariIni = Absensi::where('tanggal', $today)
            ->whereIn('status', ['tepat_waktu', 'telat'])
            ->pluck('karyawan_id')
            ->unique();

        $hasil = $departemenList->map(function ($dept) use ($pekerjaIdHadirHariIni) {
            $total = $dept->pekerja_count;

            $hadir = Pekerja::where('departemen_id', $dept->id)
                ->whereIn('id', $pekerjaIdHadirHariIni)
                ->count();

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
        $topKehadiran = Absensi::select(
                'users.name as nama',
                DB::raw('COUNT(absensis.id) as jumlah')
            )
            ->join('pekerja', 'absensis.karyawan_id', '=', 'pekerja.id')
            ->join('users', 'pekerja.user_id', '=', 'users.id')
            ->where('absensis.status', 'tepat_waktu')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get();

        return response()->json($topKehadiran);
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
    public function statsCard()
    {
        $user = Auth::user();
<<<<<<< HEAD

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $pekerja = Pekerja::where('user_id', $user->id)->first(); // cari Pekerja yang sesuai

        // Jika pekerja tidak ditemukan, kembalikan nilai default agar tidak error
        if (! $pekerja) {
            return response()->json([
                'kehadiran' => ['value' => 0, 'trend' => 'Belum ada data'],
                'izin' => ['value' => 0, 'trend' => 'Belum ada data'],
                'izinAktif' => ['value' => '-', 'trend' => 'Belum ada data'],
                'ticket' => ['value' => 0, 'trend' => 'Belum ada data'],
            ]);
        }

        $izin = PengajuanIzin::where('karyawan_id', $pekerja->id);
        $absensi = Absensi::where('karyawan_id', $pekerja->id);
=======
        $pekerja = Pekerja::where('user_id', $user->id)->first();

        $izin = PengajuanIzin::where('karyawan_id', $user->id);
        // FIX: user (misal admin murni) bisa gak punya record Pekerja, jadi absensi
        // di-scope ke pekerja->id cuma kalau pekerja-nya ada. Kalau nggak, query kosong.
        $absensi = $pekerja
            ? Absensi::where('karyawan_id', $pekerja->id)
            : Absensi::whereRaw('1 = 0');
>>>>>>> 574332644e77c23dbc8ceeede4dab9e45574c615
        $ticket = Ticket::where('user_id', $user->id);
        $value = '-';

        $izinAktif = (clone $izin)
            ->where('status', 'disetujui')
            ->latest()
            ->first();

        if ($izinAktif) {
            $mulai = Carbon::parse($izinAktif->tanggal_mulai);
            $selesai = Carbon::parse($izinAktif->tanggal_selesai);
            $hari = $mulai->diffInDays($selesai) + 1;
            $value = $hari . ' hari';
        }

        return response()->json([
            'kehadiran' => [
                'value' => (clone $absensi)->where('status', 'tepat_waktu')->count(),
                'trend' => $this->getTrend((clone $absensi)->where('status', 'tepat_waktu')),
            ],
            'izin' => [
                'value' => (clone $izin)->where('status', 'pending')->count(),
                'trend' => $this->getTrend($izin),
            ],
            'izinAktif' => [
                'value' => $value,
                'trend' => $this->getTrend($izin)
            ],
            'ticket' => [
                'value' => (clone $ticket)->where('status', 'diproses')->count(),
                'trend' => $this->getTrend($ticket)
            ]
        ]);
    }
    private function getTrend(Builder $query): string
    {
        $updatedAt = (clone $query)->max('updated_at');

        return $updatedAt
            ? 'Update terakhir ' . Carbon::parse($updatedAt)->diffForHumans()
            : 'Belum ada data';
    }
    
}