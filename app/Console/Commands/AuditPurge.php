<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditPurge extends Command
{
    protected $signature = 'audit:purge';
    protected $description = 'Hapus permanen audit log yang umurnya sudah lebih dari 1 bulan sejak dibuat';

    public function handle(): void
    {
        // Total masa hidup audit log dibatasi 1 bulan (30 hari) sejak
        // dibuat: 1 minggu pertama aktif, sisanya (sekitar 3 minggu) ada
        // di trash lewat audit:cleanup, lalu dihapus permanen di sini
        // begitu umurnya lewat 30 hari sejak created_at.
        $batas = Carbon::now()->subDays(30);

        $count = AuditLog::onlyTrashed()
            ->where('created_at', '<', $batas)
            ->forceDelete();

        $this->info("{$count} audit log dihapus permanen.");
    }
}