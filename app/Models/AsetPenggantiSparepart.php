<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPenggantianSparepart extends Model
{
    protected $table = 'aset_penggantian_sparepart';

    protected $fillable = ['aset_id', 'tanggal', 'nama_sparepart', 'keterangan', 'biaya'];

    public function aset()
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}