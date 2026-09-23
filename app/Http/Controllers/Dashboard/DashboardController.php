<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function KaryawanPerDepart()
    {
        $query = User::join('departemen', 'users.departemen_id', '=', 'departemen.id')
            ->select(
                'departemen.nama as departemen',
                DB::raw('COUNT(users.id) as jumlah')
            );

        $karyawan = $query->groupBy('departemen.nama')->get();

        $maxJumlah = $karyawan->max('jumlah') ?: 1; // fallback 1 biar ga divide by zero

        $karyawan = $karyawan->map(function ($item) use ($maxJumlah) {
            $item->percent = round(($item->jumlah / $maxJumlah) * 100);
            return $item;
        });

        return response()->json($karyawan);
    }
}