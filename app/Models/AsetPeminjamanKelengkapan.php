<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPeminjamanKelengkapan extends Model
{
    protected $table = 'aset_peminjaman_kelengkapan';

    protected $fillable = ['aset_peminjaman_id', 'aset_kelengkapan_id', 'kondisi'];

    public function peminjaman()
    {
        return $this->belongsTo(AsetPeminjaman::class, 'aset_peminjaman_id');
    }

    public function kelengkapan()
    {
        return $this->belongsTo(AsetKelengkapan::class, 'aset_kelengkapan_id');
    }
}
