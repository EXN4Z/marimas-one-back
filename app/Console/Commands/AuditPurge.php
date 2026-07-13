<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditPurge extends Command
{
    protected $signature = 'audit:purge';
    protected $description = 'Hapus permanen audit log yang sudah di trash lebih dari 7 hari';

    public function handle(): void
    {
        $batas = Carbon::now()->subDays(7);

        $count = AuditLog::onlyTrashed()
            ->where('deleted_at', '<', $batas)
            ->forceDelete();

        $this->info("{$count} audit log dihapus permanen.");
    }
}