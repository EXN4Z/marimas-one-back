<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aset extends Model
{
    protected $table = 'aset';

    protected $fillable = [
        'jenis_id',
        'merek',
        'tipe',
        'warna',
        'serial_number',
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

    public function kelengkapan()
    {
        return $this->hasMany(AsetKelengkapan::class, 'aset_id');
    }

    public function pemakai()
    {
        return $this->hasMany(AsetPemakai::class, 'aset_id')->latest('tanggal_penerimaan');
    }

    public function pemakaiSaatIni()
    {
        return $this->hasOne(AsetPemakai::class, 'aset_id')
            ->whereNull('tanggal_pengembalian')
            ->latest('tanggal_penerimaan');
    }

    public function perbaikan()
    {
        return $this->hasMany(AsetPerbaikan::class, 'aset_id')->latest('tanggal_perbaikan');
    }

    public function penggantianSparepart()
    {
        return $this->hasMany(AsetPenggantianSparepart::class, 'aset_id')->latest('tanggal');
    }
}