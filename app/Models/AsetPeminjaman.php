<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPeminjaman extends Model
{
    protected $table = 'aset_peminjaman';

    protected $fillable = [
        'aset_id', 'pekerja_id', 'nik_peminjam', 'nama_peminjam',
        'tanggal_pinjam', 'kondisi_saat_pinjam', 'no_struk_pinjam',
        'status', 'tanggal_pengembalian', 'kondisi_saat_kembali',
        'no_struk_pengembalian', 'catatan',
    ];

    protected $casts = [
        'tanggal_pinjam' => 'date',
        'tanggal_pengembalian' => 'date',
    ];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    public function pekerja()
    {
        return $this->belongsTo(Pekerja::class);
    }

    public function kelengkapanDibawa()
    {
        return $this->hasMany(AsetPeminjamanKelengkapan::class);
    }

    public function penanganan()
    {
        return $this->hasMany(AsetPenanganan::class);
    }
}
