<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PengajuanCuti;
use Illuminate\Support\Facades\DB;
use App\Models\Pekerja;
use App\Models\MutasiBarang;
use Carbon\Carbon;

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

        return response()->json($karyawan);
    }
    public function mutasiBarang() {
        Carbon::setLocale('id');

        $now = Carbon::now();

        $masuk = MutasiBarang::where('tipe', 'masuk')
                ->whereMonth('created_at', $now->month)
                ->whereYear('created_at', $now->year)
                ->sum('jumlah');

        $keluar = MutasiBarang::where('tipe', 'keluar')
                ->whereMonth('created_at', $now->month)
                ->whereYear('created_at', $now->year)
                ->sum('jumlah');

        return response()->json([
            'bulan' => Carbon::now()->translatedFormat('M'),
            'jumlah_masuk' => $masuk,
            'jumlah_keluar' => $keluar,
        ]);
    }
}
