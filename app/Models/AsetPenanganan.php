<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPenanganan extends Model
{
    protected $table = 'aset_penanganan';

    protected $fillable = [
        'aset_id', 'aset_peminjaman_id', 'jenis_kerusakan', 'keluhan',
        'tanggal_lapor', 'tanggal_selesai', 'harga_jasa', 'biaya_komponen',
        'hasil', 'no_struk', 'catatan',
    ];

    protected $casts = [
        'tanggal_lapor' => 'date',
        'tanggal_selesai' => 'date',
        'harga_jasa' => 'decimal:2',
        'biaya_komponen' => 'decimal:2',
    ];

    public function aset()
    {
        return $this->belongsTo(Aset::class);
    }

    // relasinya ke AsetPeminjaman (bukan AsetPemakai; sesuai migration aset_peminjaman_id)
    public function peminjaman()
    {
        return $this->belongsTo(AsetPeminjaman::class, 'aset_peminjaman_id');
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