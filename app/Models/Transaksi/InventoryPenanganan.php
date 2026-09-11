<?php

namespace App\Models\Transaksi;

use App\Models\MasterData\Inventory;
use Illuminate\Database\Eloquent\Model;

class InventoryPenanganan extends Model
{
    protected $table = 'inventory_penanganan';

    protected $fillable = [
        'inventory_id', 'inventory_pemakai_id', 'dilaporkan_oleh_user_id', 'jenis_kerusakan', 'keluhan', 'foto',
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

    // siapa yang BENERAN submit laporan kerusakan ini (request()->user()
    // pas store()) -- independen dari pemakai(), soalnya pelapor bisa aja
    // bukan pemakai aktif barangnya (admin/HR/manajer lapor langsung pas
    // audit gudang / barang lagi nganggur). Ini sumber kebenaran buat
    // "siapa yang lapor", pemakai() cuma buat "siapa yang lagi pegang".
    public function dilaporkanOleh()
    {
        return $this->belongsTo(\App\Models\User::class, 'dilaporkan_oleh_user_id');
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