<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MutasiBarang extends Model
{
    protected $table = 'mutasi_barang';
    protected $fillable = [
        'barang_id',
        'user_id',
        'tipe',
        'jumlah',
        'stok_sebelum',
        'stok_sesudah',
        'catatan',
    ];
    public function barang() {
        return $this->belongsTo(Barang::class);
    }
    public function user() {
        return $this->belongsTo(User::class);
    }
}
