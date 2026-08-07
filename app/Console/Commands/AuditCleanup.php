<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup';
    protected $description = 'Pindahkan audit log yang sudah lebih dari 30 hari ke trash (soft delete)';

    public function handle(): void
    {
        // Sebelumnya 24 jam -- kelewat pendek buat kebutuhan investigasi
        // aktivitas admin (misal nelusurin siapa yang pinjemin/hapus aset
        // beberapa minggu lalu). Dinaikin ke 30 hari, standar retention log
        // internal yang umum dipakai buat tool non-compliance seperti ini.
        $batas = Carbon::now()->subDays(30);

        $count = AuditLog::whereNull('deleted_at')
            ->where('created_at', '<', $batas)
            ->update(['deleted_at' => now()]);

        $this->info("{$count} audit log dipindahkan ke trash.");
    }
}