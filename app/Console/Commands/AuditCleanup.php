<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup';
    protected $description = 'Pindahkan audit log yang sudah lebih dari 1 minggu ke trash (soft delete)';

    public function handle(): void
    {
        // Audit log yang umurnya lebih dari 1 minggu (7 hari) sejak dibuat
        // dipindahkan ke trash. Masih bisa dipulihkan/dilihat lewat halaman
        // trash sampai akhirnya dihapus permanen oleh audit:purge.
        $batas = Carbon::now()->subDays(7);

        $count = AuditLog::whereNull('deleted_at')
            ->where('created_at', '<', $batas)
            ->update(['deleted_at' => now()]);

        $this->info("{$count} audit log dipindahkan ke trash.");
    }
}