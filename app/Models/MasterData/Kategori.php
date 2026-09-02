<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

/**
 * Kategori barang di Inventory (mis. "Laptop", "Charger", "Speaker" --
 * lihat seeder 13 kategori di migration reset_and_seed_kategori_baru).
 * Dikelola admin lewat CRUD biasa (Master Data), nama bebas apa saja.
 *
 * PENTING: kategori TIDAK LAGI punya makna tipe/golongan barang. Dulu ada
 * 2 baris khusus "Barang Utama"/"Kelengkapan" yang dicek by nama di banyak
 * tempat kode program (validasi parent_id, filter index, dst) -- itu semua
 * sudah dihapus. Status "item ini induk atau nempel ke item lain" sekarang
 * murni ditentukan dari Inventory::parent_id (lihat Inventory::isInduk()/
 * isChild()), sama sekali independen dari kategori apa pun yang dipilih.
 */
class Kategori extends Model
{
    protected $table = 'kategori';

    protected $fillable = [
        'nama',
    ];

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'kategori_id');
    }
}