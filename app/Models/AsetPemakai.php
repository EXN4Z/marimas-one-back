<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPemakai extends Model
{

    protected $fillable = [
        'aset_id',
        'pekerja_id',
        'status',
        'requested_by_user_id',
        'nomor_penerimaan',
        'tanggal_penerimaan',
        'catatan_penerimaan',
        'nomor_pengembalian',
        'tanggal_pengembalian',
        'catatan_pengembalian',
        'catatan_penolakan',
    ];
    
    public function aset()
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
    
    public function pekerja()
    {
        return $this->belongsTo(Pekerja::class, 'pekerja_id');
    }
    
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
    
}