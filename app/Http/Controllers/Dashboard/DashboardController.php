<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
    public function KaryawanPerDepart()
    {
        // UBAH: di-scope ke cabang. Base query sekarang langsung tabel
        // users (dulu tabel pekerja, yang udah dihapus total), pakai
        // relasiKeKaryawan = '' supaya helper langsung filter kolom
        // users.lokasi_kantor_id. Role 'cabang' sendiri dikecualikan,
        // karena akun cabang bukan karyawan yang mau dihitung per
        // departemen.
        $query = User::join('departemen', 'users.departemen_id', '=', 'departemen.id')
            ->where('users.role', '!=', 'cabang')
            ->select(
                'departemen.nama as departemen',
                DB::raw('COUNT(users.id) as jumlah')
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

    // BARU: helper buat nge-scope query manapun ke lokasi_kantor_id akun
    // cabang yang login.
    //
    // - $relasiKeKaryawan diisi nama relasi (bisa nested, misal
    //   'karyawan') kalau base model query-nya BUKAN User -- helper akan
    //   whereHas ke relasi itu lalu filter lokasi_kantor_id di ujungnya.
    // - $relasiKeKaryawan diisi string kosong '' kalau base model
    //   query-nya SUDAH User itu sendiri (misal User::query()) -- helper
    //   filter langsung kolom users.lokasi_kantor_id tanpa whereHas.
    //
    // Kalau yang login bukan role 'cabang' (atau lokasi_kantor_id belum
    // diset), query dibiarin apa adanya (gak difilter).
    private function scopeQueryKeCabang(Builder $query, string $relasiKeKaryawan): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'cabang' || ! $user->lokasi_kantor_id) {
            return;
        }

        if ($relasiKeKaryawan === '') {
            $query->where('users.lokasi_kantor_id', $user->lokasi_kantor_id);
            return;
        }

        $query->whereHas($relasiKeKaryawan, function ($q) use ($user) {
            $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
        });
    }
}