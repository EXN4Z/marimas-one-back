<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPenanganan extends Model
{
    protected $table = 'aset_penanganan';

    protected $fillable = [
        'aset_id', 'aset_pemakai_id', 'jenis_kerusakan', 'keluhan',
        'tanggal_lapor', 'tanggal_selesai', 'harga_jasa', 'biaya_komponen',
        'hasil', 'no_struk', 'catatan',
    ];

    protected $casts = [
        'tanggal_lapor' => 'date',
        'tanggal_selesai' => 'date',
        'harga_jasa' => 'decimal:2',
        'biaya_komponen' => 'decimal:2',
    ];

    // frontend butuh dua ini ikut kekirim di JSON, bukan cuma keitung pas dipanggil manual
    protected $appends = ['total_biaya', 'durasi_hari'];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    // siapa yang lagi pegang aset ini pas dilaporkan rusak (nullable, bisa juga
    // ketauan pas aset nganggur / audit gudang)
    public function pemakai()
    {
        return $this->belongsTo(AsetPemakai::class, 'aset_pemakai_id');
    }

    public function getTotalBiayaAttribute(): float
    {
        return (float) $this->harga_jasa + (float) $this->biaya_komponen;
    }

    public function getDurasiHariAttribute(): ?int
    {
        if (!$this->tanggal_selesai) {
            return null;
        }
        return $this->tanggal_lapor->diffInDays($this->tanggal_selesai);
    }
}