<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AuditVerifyScenario extends Command
{
    protected $signature = 'audit:verify-scenario';
    protected $description = 'SEMENTARA: buat data dummy, jalankan cleanup+purge, cek hasilnya, lalu bersihkan sendiri';

    public function handle(): void
    {
        $this->info('== Bikin data dummy ==');

        $logTua = $this->buatLog('/test-cleanup', 8, null);       // harus masuk trash
        $logBaru = $this->buatLog('/test-fresh', 3, null);        // belum boleh masuk trash

        $logExpired = $this->buatLog('/test-purge-expired', 40, 35);   // harus kehapus permanen
        $logMasihAman = $this->buatLog('/test-purge-safe', 15, 8);     // belum boleh kehapus

        $this->newLine();
        $this->info('== Jalankan audit:cleanup ==');
        $this->call('audit:cleanup');

        $this->newLine();
        $this->info('== Cek hasil cleanup ==');
        $this->cekStatus($logTua->id, 'log umur 8 hari (harusnya SUDAH di trash)', true);
        $this->cekStatus($logBaru->id, 'log umur 3 hari (harusnya BELUM di trash)', false);

        $this->newLine();
        $this->info('== Jalankan audit:purge ==');
        $this->call('audit:purge');

        $this->newLine();
        $this->info('== Cek hasil purge ==');
        $expiredMasihAda = AuditLog::withTrashed()->find($logExpired->id);
        $this->line($expiredMasihAda
            ? "❌ GAGAL: log expired (created 40 hari, trashed 35 hari) masih ada, harusnya sudah kehapus permanen"
            : "✅ OK: log expired sudah kehapus permanen");

        $amanMasihAda = AuditLog::withTrashed()->find($logMasihAman->id);
        $this->line($amanMasihAda
            ? "✅ OK: log yang masih aman (created 15 hari, trashed 8 hari) masih ada"
            : "❌ GAGAL: log yang masih aman malah ikut kehapus");

        $this->newLine();
        $this->info('== Bersihkan data dummy ==');
        $sisaId = [$logTua->id, $logBaru->id, $logMasihAman->id];
        $dihapus = AuditLog::withTrashed()->whereIn('id', $sisaId)->forceDelete();
        $this->info("{$dihapus} data dummy dibersihkan. Selesai.");
    }

    private function buatLog(string $endpoint, int $umurHari, ?int $ditrashSejakHari): AuditLog
    {
        $log = AuditLog::create([
            'user_id' => null,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'deskripsi' => 'dummy verifikasi retensi',
        ]);

        $log->created_at = Carbon::now()->subDays($umurHari);

        if ($ditrashSejakHari !== null) {
            $log->deleted_at = Carbon::now()->subDays($ditrashSejakHari);
        }

        $log->save();

        return $log;
    }

    private function cekStatus(int $id, string $label, bool $harusnyaSudahDitrash): void
    {
        $log = AuditLog::withTrashed()->find($id);
        $sudahDitrash = $log->deleted_at !== null;

        $ok = $sudahDitrash === $harusnyaSudahDitrash;

        $this->line(($ok ? '✅ OK: ' : '❌ GAGAL: ') . $label);
    }
}
