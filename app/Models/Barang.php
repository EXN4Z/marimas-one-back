<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barang extends Model
{
    protected $table = 'barang';

    protected $fillable = [
        'kode_barang',
        'nama',
        'kategori_id',
        'satuan',
        'stok',
        'stok_minimum',
        'harga',
    ];

    public function mutasi()
    {
        return $this->hasMany(MutasiBarang::class);
    }

    public function kategoriBarang()
    {
        return $this->belongsTo(KategoriBarang::class, 'kategori_id');
    }
}