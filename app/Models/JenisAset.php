<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisAset extends Model
{
    protected $table = 'jenis_aset';

    protected $fillable = ['nama', 'kategori_id'];

    // Tetap muncul sebagai "kategori": "aset_utama"/"kelengkapan" di JSON,
    // biar kode lama (frontend, whereHas di controller lain, import Excel)
    // yang masih ngerujuk ke $jenis->kategori atau field JSON "kategori"
    // nggak perlu ikut diubah walau datanya sekarang di tabel `kategori`
    // terpisah. Nilainya diambil dari kategori.kode lewat relasi di bawah.
    protected $appends = ['kategori'];

    public function aset()
    {
        return $this->hasMany(Aset::class, 'jenis_id');
    }

    // Relasi ke tabel kategori (terpisah). Nama method sengaja bukan
    // `kategori()` biar nggak tabrakan sama accessor getKategoriAttribute()
    // di bawah, yang tetap mengembalikan string kode lama demi kompatibilitas.
    public function kategoriData()
    {
        return $this->belongsTo(Kategori::class, 'kategori_id');
    }

    public function getKategoriAttribute(): ?string
    {
        return $this->kategoriData?->kode;
    }

    // scope buat filter dropdown Tambah Aset (pilih jenis aset utama) vs
    // checklist kelengkapan di form peminjaman (pilih jenis kelengkapan).
    public function scopeAsetUtama($query)
    {
        return $query->whereHas('kategoriData', fn ($q) => $q->where('kode', 'aset_utama'));
    }

    public function scopeKelengkapan($query)
    {
        return $query->whereHas('kategoriData', fn ($q) => $q->where('kode', 'kelengkapan'));
    }

    // Helper: filter jenis_aset by kode kategori ('aset_utama'/'kelengkapan')
    // tanpa harus tahu id-nya -- dipakai controller & import Excel.
    public function scopeKategoriKode($query, string $kode)
    {
        return $query->whereHas('kategoriData', fn ($q) => $q->where('kode', $kode));
    }
}