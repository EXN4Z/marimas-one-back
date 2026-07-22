<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPemakai extends Model
{
    protected $table = 'aset_pemakai';

    protected $fillable = [
        'aset_id',
        'pekerja_id',
        'status',
        'requested_by_user_id',
        'nomor_penerimaan',
        'no_struk_penerimaan',
        'tanggal_penerimaan',
        'catatan_penerimaan',
        'nomor_pengembalian',
        'no_struk_pengembalian',
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

    public function penanganan()
    {
        return $this->hasMany(AsetPenanganan::class, 'aset_pemakai_id');
    }
    
}