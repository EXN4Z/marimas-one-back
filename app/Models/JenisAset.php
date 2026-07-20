<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisAset extends Model
{
    protected $table = 'jenis_aset';

    protected $fillable = ['nama'];

    public function aset()
    {
        return $this->hasMany(Aset::class, 'jenis_id');
    }
}