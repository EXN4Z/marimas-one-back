<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Absensi extends Model
{
    protected $fillable = [
        'karyawan_id',
        'tanggal',
        'jam_masuk',
        'jam_pulang',
        'status',
    ];

    public function pekerja()
    {
        return $this->belongsTo(Pekerja::class, 'karyawan_id');
    }
}