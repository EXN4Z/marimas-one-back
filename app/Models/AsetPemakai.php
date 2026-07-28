<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsetPemakai extends Model
{
    protected $table = 'aset_pemakai';

    protected $fillable = [
        'aset_id',
        'pekerja_id',
        'user_id',
        'status',
        'requested_by_user_id',
        'nomor_penerimaan',
        'no_struk_penerimaan',
        'tanggal_penerimaan',
        'diterima_at',
        'catatan_penerimaan',
        'nomor_pengembalian',
        'no_struk_pengembalian',
        'tanggal_pengembalian',
        'dikembalikan_at',
        'catatan_pengembalian',
        'catatan_penolakan',
    ];

    // *_at (datetime lengkap jam-menit-detik) — dipakai buat riwayat aktivitas
    // yang butuh waktu akurat. Beda sama tanggal_* (cuma tanggal, dipakai buat
    // tampilan & bisnis logic lain) — lihat migration
    // add_waktu_akurat_ke_aset_pemakai_dan_penanganan.
    protected $casts = [
        'tanggal_penerimaan' => 'date',
        'tanggal_pengembalian' => 'date',
        'diterima_at' => 'datetime',
        'dikembalikan_at' => 'datetime',
    ];
    
    public function aset()
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
    
    public function pekerja()
    {
        return $this->belongsTo(Pekerja::class, 'pekerja_id');
    }
    
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function penanganan()
    {
        return $this->hasMany(AsetPenanganan::class, 'aset_pemakai_id');
    }
    public function user() {
        return $this->belongsTo(User::class);
    }
}