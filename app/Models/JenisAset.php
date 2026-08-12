<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisAset extends Model
{
    protected $table = 'jenis_aset';

    protected $fillable = ['nama', 'kategori'];

    public function aset()
    {
        return $this->hasMany(Aset::class, 'jenis_id');
    }

    // scope buat filter dropdown Tambah Aset (pilih jenis aset utama) vs
    // checklist kelengkapan di form peminjaman (pilih jenis kelengkapan).
    public function scopeAsetUtama($query)
    {
        return $query->where('kategori', 'aset_utama');
    }

    public function scopeKelengkapan($query)
    {
        return $query->where('kategori', 'kelengkapan');
    }
}