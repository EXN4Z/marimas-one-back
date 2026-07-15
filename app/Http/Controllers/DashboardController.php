<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PengajuanCuti;
use Illuminate\Support\Facades\DB;
use App\Models\Pekerja;
use App\Models\MutasiBarang;
use Carbon\Carbon;
use App\Models\PengajuanIzin;
use App\Models\Absensi;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
    public function analisisCuti() {
        return response()->json([
            'total' => PengajuanCuti::count(),
            'pending' => PengajuanCuti::where('status', 'pending')->count(),
            'disetujui' => PengajuanCuti::where('status', 'disetujui')->count(),
            'ditolak' => PengajuanCuti::where('status', 'ditolak')->count(),
        ]);
    }
    public function topKaryawan()
    {
        $topKaryawan = PengajuanCuti::select(
                'users.name as nama',
                DB::raw('COUNT(pengajuan_cuti.id) as jumlah')
            )
            ->join('users', 'pengajuan_cuti.karyawan_id', '=', 'users.id')
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

            $jumlah = PengajuanCuti::whereMonth('tanggal_mulai', $bulan->month)
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
        $izin = PengajuanIzin::where('karyawan_id', $user->id);
        $absensi = Absensi::where('karyawan_id', $user->id);
        $ticket = Ticket::where('user_id', $user->id);
        $value = '-';

        // UBAH: eksekusi query-nya, ambil 1 record cuti yang relevan (bukan builder mentah)
        $cutiAktif = PengajuanCuti::where('karyawan_id', $user->id)
            ->where('status', 'disetujui') // sesuaikan status yang dianggap "aktif/berlaku"
            ->latest()
            ->first();

        if ($cutiAktif) {
            $mulai = Carbon::parse($cutiAktif->tanggal_mulai);
            $selesai = Carbon::parse($cutiAktif->tanggal_selesai);

            // +1 karena tanggal mulai dan selesai ikut dihitung
            $hari = $mulai->diffInDays($selesai) + 1;

            $value = $hari . ' hari';
        }

        // trend juga masih butuh query builder $cuti yang belum dieksekusi,
        // jadi tetap disiapkan terpisah buat dilempar ke getTrend()
        $cuti = PengajuanCuti::where('karyawan_id', $user->id);

        return response()->json([
            'kehadiran' => [
                'value' => (clone $absensi)->count(),
                'trend' => $this->getTrend($absensi),
            ],
            'izin' => [
                'value' => (clone $izin)->where('status', 'pending')->count(),
                'trend' => $this->getTrend($izin),
            ],
            'cuti' => [
                'value' => $value,
                'trend' => $this->getTrend($cuti)
            ],
            'ticket' => [
                'value' => (clone $ticket)->count(),
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
