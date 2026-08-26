<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

/**
 * Kategori barang di Inventory (mis. "Barang Utama", "Kelengkapan").
 * Dikelola admin lewat CRUD biasa (Master Data) -- BEDA dari versi lama
 * yang cuma 2 baris fix & read-only (`kode` enum wajib unik). Sekarang
 * `kode` cuma abbreviation opsional (nullable, gak unik), gak dipakai buat
 * identifikasi program.
 *
 * Klasifikasi Barang Utama/Kelengkapan yang dipakai di banyak tempat kode
 * program (validasi parent_id, filter index, dst) sekarang dicek langsung
 * dari `nama` ("Barang Utama" / "Kelengkapan"), BUKAN dari `kode`. Baris
 * dengan nama selain itu (kalau admin nambah kategori baru lewat CRUD)
 * dianggap bukan salah satu dari keduanya oleh isBarangUtama()/isKelengkapan().
 */
class Kategori extends Model
{
    protected $table = 'kategori';

    protected $fillable = [
        'nama',
        'kode',
    ];

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'kategori_id');
    }

    public function isBarangUtama(): bool
    {
        return $this->nama === 'Barang Utama';
    }

    public function isKelengkapan(): bool
    {
        return $this->nama === 'Kelengkapan';
    }
}