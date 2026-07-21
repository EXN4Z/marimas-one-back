<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetKelengkapan extends Model
{
    protected $table = 'aset_kelengkapan';

    protected $fillable = ['aset_id', 'kelengkapan_master_id', 'keterangan'];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    public function kelengkapanMaster()
    {
        return $this->belongsTo(KelengkapanMaster::class, 'kelengkapan_master_id');
    }
}