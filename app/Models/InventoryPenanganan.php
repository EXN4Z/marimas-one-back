<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryPenanganan extends Model
{
    protected $table = 'inventory_penanganan';

    protected $fillable = [
        'inventory_id', 'inventory_pemakai_id', 'jenis_kerusakan', 'keluhan', 'foto',
        'tanggal_lapor', 'lapor_at',
        'tanggal_diterima', 'diterima_at',
        'tanggal_selesai', 'selesai_at',
        'harga_jasa', 'biaya_komponen',
        'hasil', 'no_struk', 'catatan',
    ];

    // *_at (datetime lengkap) dipakai buat riwayat aktivitas yang butuh
    // waktu akurat — tanggal_* (cuma tanggal) tetap dipertahankan buat
    // tampilan & perhitungan durasi_hari.
    protected $casts = [
        'tanggal_lapor' => 'date',
        'tanggal_diterima' => 'date',
        'tanggal_selesai' => 'date',
        'lapor_at' => 'datetime',
        'diterima_at' => 'datetime',
        'selesai_at' => 'datetime',
        'harga_jasa' => 'decimal:2',
        'biaya_komponen' => 'decimal:2',
    ];

    // frontend butuh dua ini ikut kekirim di JSON, bukan cuma keitung pas
    // dipanggil manual
    protected $appends = ['total_biaya', 'durasi_hari'];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    // siapa yang lagi pegang item ini pas dilaporkan rusak (nullable, bisa
    // juga ketauan pas item nganggur / audit gudang)
    public function pemakai()
    {
        return $this->belongsTo(InventoryPemakai::class, 'inventory_pemakai_id');
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