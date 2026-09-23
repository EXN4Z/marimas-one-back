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
    ];

    public function karyawan(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id');
    }

    // Semua akun yang nunjuk ke lokasi ini, termasuk akun cabang sendiri.
    // Superset dari karyawan().
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id');
    }
}