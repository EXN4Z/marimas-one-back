<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

/**
 * Cuma 2 baris fix: "Barang Utama" (kode: barang_utama) & "Kelengkapan"
 * (kode: kelengkapan). Di-seed langsung di migration, BUKAN lewat Master
 * Data UI -- kode dipakai di banyak tempat di kode program (validasi
 * parent_id, filter index, dst), jangan diubah/dihapus user lewat CRUD.
 */
class Kategori extends Model
{
    protected $table = 'kategori';

    protected $fillable = [
        'nama',
        'kode',
    ];

    public function masterKategori()
    {
        return $this->hasMany(MasterKategori::class, 'kategori_id');
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