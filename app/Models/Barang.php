<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barang extends Model
{
    protected $table = 'barang';

    protected $fillable = [
        'kode_barang',
        'nama',
        'kategori',
        'satuan',
        'stok',
        'stok_minimum',
    ];

    public function mutasi() {
        return $this->hasMany(MutasiBarang::class);
    }
}
