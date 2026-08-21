<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryPemakai extends Model
{
    protected $table = 'inventory_pemakai';

    protected $fillable = [
        'inventory_id',
        'user_id',
        'status',
        'requested_by_user_id',
        'nomor_penerimaan',
        'no_struk_penerimaan',
        'tanggal_penerimaan',
        'foto_penerimaan',
        'diterima_at',
        'catatan_penerimaan',
        'nomor_pengembalian',
        'no_struk_pengembalian',
        'tanggal_pengembalian',
        'foto_pengembalian',
        'dikembalikan_at',
        'catatan_pengembalian',
        'catatan_penolakan',
    ];

    // *_at (datetime lengkap jam-menit-detik) — dipakai buat riwayat
    // aktivitas yang butuh waktu akurat. Beda sama tanggal_* (cuma tanggal).
    protected $casts = [
        'tanggal_penerimaan' => 'date',
        'tanggal_pengembalian' => 'date',
        'diterima_at' => 'datetime',
        'dikembalikan_at' => 'datetime',
        'foto_penerimaan' => 'array',
        'foto_pengembalian' => 'array',
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function penanganan()
    {
        return $this->hasMany(InventoryPenanganan::class, 'inventory_pemakai_id');
    }
}