<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LokasiKantor extends Model
{
    protected $table = 'lokasi_kantor';

    protected $fillable = [
        'nama',
        'alamat',
        'telepon',
        'link',
    ];

    // BARU (eks-pekerja): karyawan yang kerja di cabang ini — dikecualikan
    // akun role 'cabang' sendiri, biar cabang gak kehitung sebagai
    // pegawainya sendiri (penting buat cek "cabang masih ada pegawai?"
    // sebelum boleh dihapus, lihat CabangController).
    public function karyawan(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id')
            ->whereHas('roleRef', fn ($q) => $q->where('nama', '!=', 'cabang'));
    }

    // Semua akun yang nunjuk ke lokasi ini, termasuk akun cabang sendiri.
    // Superset dari karyawan().
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id');
    }
    public function akunCabang(): HasOne
    {
        return $this->hasOne(User::class, 'lokasi_kantor_id')
            ->whereHas('roleRef', fn ($q) => $q->where('nama', 'cabang'));
    }
}