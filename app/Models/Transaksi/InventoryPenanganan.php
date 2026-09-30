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
    // tampilan.
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
    protected $appends = ['total_biaya', 'durasi_hari', 'durasi_detik'];

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

    // Selisih hari kalender lapor -> selesai. Pakai kolom *_at (waktu kejadian
    // sebenarnya), fallback ke tanggal_* buat data lama yang *_at-nya kosong.
    public function getDurasiHariAttribute(): ?int
    {
        if (!$this->tanggal_selesai || !$this->tanggal_lapor) {
            return null;
        }

        $mulai   = ($this->lapor_at ?? $this->tanggal_lapor)->copy()->startOfDay();
        $selesai = ($this->selesai_at ?? $this->tanggal_selesai)->copy()->startOfDay();

        return max(0, (int) $mulai->diffInDays($selesai, false));
    }

    // Durasi PENGERJAAN dalam DETIK: dari teknisi menerima -> selesai.
    // (waktu tunggu sebelum diterima gak dihitung). Kalau belum ada
    // diterima_at, fallback ke lapor_at, lalu ke kolom tanggal_* (jam 00:00).
    // Sengaja TIDAK lagi ngecek isSameDay: tanggal_selesai bisa diisi manual
    // lewat form dan gak selalu sama harinya dengan selesai_at, dan itu yang
    // bikin durasi ngaco (mis. 13 jam padahal cuma 2 jam).
    // Format tampilan ("1d 2h", "5m 10s") dilakukan di frontend.
    public function getDurasiDetikAttribute(): ?int
    {
        if (!$this->tanggal_selesai || !$this->tanggal_lapor) {
            return null;
        }

        $mulai = $this->diterima_at
            ?? $this->lapor_at
            ?? $this->tanggal_diterima
            ?? $this->tanggal_lapor;
        $selesai = $this->selesai_at ?? $this->tanggal_selesai;

        return max(0, (int) $mulai->diffInSeconds($selesai, false));
    }
}