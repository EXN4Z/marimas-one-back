<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\DB;
use App\Models\Pekerja;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
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

    // BARU: helper buat nge-scope query manapun ke lokasi_kantor_id akun
    // cabang yang login.
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
}