<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetKelengkapan extends Model
{
    protected $table = 'aset_kelengkapan';

    protected $fillable = [
        'aset_id',
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
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
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
    public function aset() {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}