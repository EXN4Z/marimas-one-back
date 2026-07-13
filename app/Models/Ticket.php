<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'user_id',
        'judul',
        'deskripsi',
        'kategori',
        'status',
        'catatan_admin',
        'ditangani_oleh',
        'selesai_at',
    ];

    protected $casts = [
        'selesai_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DITOLAK = 'ditolak';

    // Laporan yang masih berjalan -> tampil di daftar "sedang/pending proses"
    public const STATUS_AKTIF = [self::STATUS_PENDING, self::STATUS_DIPROSES];

    // Laporan yang sudah tuntas -> tampil di "history"
    public const STATUS_HISTORY = [self::STATUS_SELESAI, self::STATUS_DITOLAK];

    public function pelapor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function penanggungJawab()
    {
        return $this->belongsTo(User::class, 'ditangani_oleh');
    }
}