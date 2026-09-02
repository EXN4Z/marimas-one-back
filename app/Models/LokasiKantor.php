<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LokasiKantor extends Model
{
    protected $table = 'lokasi_kantor';

    protected $fillable = [
        'nama',
        'alamat',
        'telepon',
        'link',
        // FIX: latitude & longitude sebelumnya gak ada di $fillable, padahal
        // kolomnya WAJIB diisi (NOT NULL, tanpa default) di migration --
        // akibatnya create()/update() lewat mass-assignment selalu gagal
        // (Eloquent diam-diam nge-drop field yang gak fillable, lalu DB
        // nolak insert/update karena kolom wajib itu kosong).
        'latitude',
        'longitude',
    ];

    // BARU (eks-pekerja): karyawan yang kerja di cabang ini — dikecualikan
    // akun role 'cabang' sendiri, biar cabang gak kehitung sebagai
    // pegawainya sendiri (penting buat cek "cabang masih ada pegawai?"
    // sebelum boleh dihapus, lihat CabangController).
    public function karyawan(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id')->where('role', '!=', 'cabang');
    }

    // Semua akun yang nunjuk ke lokasi ini, termasuk akun cabang sendiri.
    // Superset dari karyawan().
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id');
    }
}