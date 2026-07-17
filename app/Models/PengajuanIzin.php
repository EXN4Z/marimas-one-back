<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PengajuanIzin extends Model
{
    protected $table = 'pengajuan_izin';

    protected $fillable = [
        'nomor_izin',
        'karyawan_id',
        'jenis_izin',
        'tanggal_mulai',
        'tanggal_selesai',
        'alasan',
        'bukti_path',
        'kontak_darurat',
        'status',
        'catatan_atasan',
        'direview_oleh',
        'direview_at',
    ];

    protected $casts = [
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'direview_at' => 'datetime',
    ];

    protected $appends = ['lama_izin'];

    public const JENIS = ['tahunan', 'pribadi', 'sakit', 'terlambat', 'pulang_cepat', 'dinas', 'lainnya'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_REVISI = 'revisi';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DRAFT = 'draft';

    public function karyawan()
    {
        return $this->belongsTo(User::class, 'karyawan_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'direview_oleh');
    }

    // Jumlah hari izin (inklusif tanggal mulai & selesai)
    public function getLamaIzinAttribute(): int
    {
        if (!$this->tanggal_mulai || !$this->tanggal_selesai) {
            return 0;
        }

        return $this->tanggal_mulai->diffInDays($this->tanggal_selesai) + 1;
    }

    // Generate nomor unik format IZN-{YY}{4 digit urut per tahun}, contoh: IZN-240001
    public static function generateNomorIzin(?Carbon $tanggal = null): string
    {
        $tanggal ??= now();
        $tahunPendek = $tanggal->format('y');

        $terakhir = static::where('nomor_izin', 'like', "IZN-{$tahunPendek}%")
            ->orderByDesc('id')
            ->first();

        $urutan = 1;
        if ($terakhir) {
            $urutanTerakhir = (int) substr($terakhir->nomor_izin, -4);
            $urutan = $urutanTerakhir + 1;
        }

        return sprintf('IZN-%s%04d', $tahunPendek, $urutan);
    }
}