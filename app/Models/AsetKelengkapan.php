<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetKelengkapan extends Model
{
    protected $table = 'aset_kelengkapan';

    protected $fillable = [
        'jenis_id',
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

    public function jenis()
    {
        return $this->belongsTo(JenisAset::class, 'jenis_id');
    }

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
}