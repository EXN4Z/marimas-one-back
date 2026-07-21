<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetWriteoff extends Model
{
    protected $table = 'aset_writeoff';

    protected $fillable = [
        'aset_id', 'disetujui_oleh', 'alasan', 'no_berita_acara',
        'tanggal_writeoff', 'catatan',
    ];

    protected $casts = [
        'tanggal_writeoff' => 'date',
    ];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    public function penyetuju()
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }
}
