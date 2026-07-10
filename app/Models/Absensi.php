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
    ];

    public function karyawan()
    {
        return $this->belongsTo(User::class, 'karyawan_id');
    }
}
