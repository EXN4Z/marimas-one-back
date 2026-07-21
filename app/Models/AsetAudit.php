<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetAudit extends Model
{
    protected $table = 'aset_audit';

    protected $fillable = ['aset_id', 'petugas_id', 'tanggal_audit', 'hasil', 'catatan'];

    protected $casts = [
        'tanggal_audit' => 'date',
    ];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    public function petugas()
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }
}
