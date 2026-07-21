<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetKelengkapan extends Model
{
    protected $table = 'aset_kelengkapan';

    protected $fillable = ['aset_id', 'nama', 'serial_number', 'keterangan'];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }
}
