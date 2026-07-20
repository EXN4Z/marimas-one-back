<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPerbaikan extends Model
{
    protected $table = 'aset_perbaikan';

    protected $fillable = [
        'aset_id',
        'tanggal_perbaikan',
        'keterangan_kerusakan',
        'teknisi_vendor',
        'biaya',
        'status',
        'tanggal_selesai',
    ];

    public function aset()
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}