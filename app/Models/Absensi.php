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
        'status_pulang',        // BARU: sebelumnya juga kepakai di controller tapi belum ada di fillable
        'photo_path',           // BARU
        'latitude',             // BARU
        'longitude',            // BARU
        'distance_from_office', // BARU
        'face_verified',
        'face_match_distance',
    ];

    public function pekerja()
    {
        return $this->belongsTo(Pekerja::class, 'karyawan_id');
    }
}