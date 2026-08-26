<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

/**
 * Cuma 2 baris fix: "Barang Utama" (kode: barang_utama) & "Kelengkapan"
 * (kode: kelengkapan), di-seed langsung di migration.
 *
 * PERHATIAN: model ini sekarang full-CRUD lewat Master Data UI. Kolom
 * `kode` dipakai di banyak tempat di kode program (validasi parent_id,
 * filter index, isBarangUtama()/isKelengkapan(), dst) -- kalau user
 * mengedit/menghapus 2 baris ini lewat UI, fitur-fitur yang bergantung
 * padanya bisa rusak. Pertimbangkan proteksi di level Controller/Policy
 * (mis. blokir delete/update kolom `kode` untuk 2 baris seed ini).
 */
class Kategori extends Model
{
    protected $table = 'kategori';

    protected $fillable = [
        'nama',
    ];

    public function inventory() {
        return $this->hasMany(Inventory::class, 'kategori_id');
    }

    public function isBarangUtama(): bool
    {
        return $this->kode === 'barang_utama';
    }

    public function isKelengkapan(): bool
    {
        return $this->kode === 'kelengkapan';
    }
}