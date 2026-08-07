<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditPurge extends Command
{
    protected $signature = 'audit:purge';
    protected $description = 'Hapus permanen audit log yang sudah di trash lebih dari 90 hari';

    public function handle(): void
    {
        // Sebelumnya 7 hari (total retention ~8 hari). Dinaikin ke 90 hari
        // di trash (total retention ~120 hari / 4 bulan) supaya masih bisa
        // ditelusuri kalau ada kejadian yang baru ketauan belakangan.
        $batas = Carbon::now()->subDays(90);

        $count = AuditLog::onlyTrashed()
            ->where('deleted_at', '<', $batas)
            ->forceDelete();

        $this->info("{$count} audit log dihapus permanen.");
    }
}