<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jenis/tipe barang (mis. Laptop, Proyektor, Charger, Tas) -- pengganti
 * `jenis_aset` lama. Beda sama Kategori (yang cuma 2 baris fix): ini yang
 * diatur admin lewat Master Data (CRUD biasa), tiap baris nempel ke salah
 * satu Kategori (Barang Utama / Kelengkapan).
 */
class MasterKategori extends Model
{
    protected $table = 'master_kategori';

    protected $fillable = [
        'nama',
        'kode',
        'kategori_id',
    ];

    public function kategori()
    {
        return $this->belongsTo(Kategori::class, 'kategori_id');
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'master_kategori_id');
    }
}