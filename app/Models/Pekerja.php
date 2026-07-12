<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pekerja extends Model
{
    protected $table = 'pekerja';

    protected $fillable = [
        'user_id',
        'nip',
        'divisi_id',
        'jabatan_id',
        'qr_code',
        'tanggal_masuk',
        'foto',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function divisi()
    {
        return $this->belongsTo(Divisi::class, 'divisi_id');
    }

    public function jabatan()
    {
        return $this->belongsTo(Jabatan::class, 'jabatan_id');
    }
}