<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetKelengkapan extends Model
{
    protected $table = 'aset_kelengkapan';

    protected $fillable = [
        'aset_id',
        'lokasi_kantor_id',
        'nama',
        'merek',
        'tipe',
        'warna',
        'serial_number',
        'tanggal_garansi',
        'perusahaan',
        'keterangan',
        'foto',
        'supplier_id',
        'tanggal_pembelian',
        'no_surat_jalan',
        'no_good_receive',
        'status',
        'tanggal_rusak',
    ];

    protected $casts = [
        'tanggal_rusak' => 'datetime',
    ];

    public function scopeRusak($query)
    {
        return $query->where('status', 'rusak');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    // aset induk tempat kelengkapan ini menempel (mis. mouse punya laptop tertentu)
    public function aset()
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }

    // lokasi kelengkapan ini kalau BERDIRI SENDIRI (tanpa aset induk) --
    // dipakai buat nandain kelengkapan itu fisiknya ada di kantor/cabang mana.
    public function lokasiKantor()
    {
        return $this->belongsTo(LokasiKantor::class, 'lokasi_kantor_id');
    }

    public function pemakai()
    {
        return $this->hasMany(AsetPemakai::class, 'aset_kelengkapan_id')->latest('tanggal_penerimaan');
    }

    public function pemakaiSaatIni()
    {
        return $this->hasOne(AsetPemakai::class, 'aset_kelengkapan_id')
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->latest('tanggal_penerimaan');
    }

    public function pemakaiPending()
    {
        return $this->hasMany(AsetPemakai::class, 'aset_kelengkapan_id')
            ->where('status', 'pending')
            ->latest();
    }
}