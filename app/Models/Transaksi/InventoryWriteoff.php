<?php

namespace App\Models\Transaksi;

use App\Models\MasterData\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InventoryWriteoff extends Model
{
    protected $table = 'inventory_writeoff';

    protected $fillable = [
        'inventory_id', 'disetujui_oleh', 'alasan', 'no_berita_acara',
        'tanggal_writeoff', 'catatan',
    ];

    protected $casts = [
        'tanggal_writeoff' => 'date',
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function penyetuju()
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }
}