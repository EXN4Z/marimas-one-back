<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pekerja extends Model
{
    protected $table = 'pekerja';

    protected $fillable = [
        'user_id',
        'nip',
        'departemen_id',
        'lokasi_kantor_id',
        'jabatan_id',
        'qr_code',
        'tanggal_masuk',
        'foto',
        'face_descriptor',
    ];
    protected $casts = [
        'face_descriptor' => 'array',
        'kuota_izin_tahunan' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function departemen()
    {
        return $this->belongsTo(Departemen::class, 'departemen_id');
    }

    public function jabatan()
    {
        return $this->belongsTo(Jabatan::class, 'jabatan_id');
    }
    public function lokasiKantor()
    {
    return $this->belongsTo(LokasiKantor::class, 'lokasi_kantor_id');
    }   
}