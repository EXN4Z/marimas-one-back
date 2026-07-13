<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup';
    protected $description = 'Pindahkan audit log yang sudah lebih dari 24 jam ke trash (soft delete)';

    public function handle(): void
    {
        $batas = Carbon::now()->subHours(24);

        $count = AuditLog::whereNull('deleted_at')
            ->where('created_at', '<', $batas)
            ->update(['deleted_at' => now()]);

        $this->info("{$count} audit log dipindahkan ke trash.");
    }
}