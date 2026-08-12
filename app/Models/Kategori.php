<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kategori extends Model
{
    protected $table = 'kategori';

    protected $fillable = ['nama', 'kode'];

    public function jenisAset()
    {
        return $this->hasMany(JenisAset::class, 'kategori_id');
    }
}
