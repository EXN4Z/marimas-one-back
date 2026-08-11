<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_di_trash_yang_umurnya_lebih_dari_1_bulan_dihapus_permanen(): void
    {
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(31))
            ->trashedAt(Carbon::now()->subDays(20))
            ->create();

        $this->artisan('audit:purge')
            ->expectsOutputToContain('1 audit log dihapus permanen.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $log->id]);
    }

    public function test_log_di_trash_yang_umurnya_belum_1_bulan_tidak_dihapus(): void
    {
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(15))
            ->trashedAt(Carbon::now()->subDays(8))
            ->create();

        $this->artisan('audit:purge')
            ->expectsOutputToContain('0 audit log dihapus permanen.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_log_aktif_yang_belum_ditrash_tidak_ikut_dihapus_walau_sudah_tua(): void
    {
        // Umurnya lebih dari 1 bulan tapi belum pernah di-soft-delete
        // (misal audit:cleanup belum sempat jalan) -> tidak boleh dihapus.
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(45))
            ->create();

        $this->artisan('audit:purge')
            ->expectsOutputToContain('0 audit log dihapus permanen.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_hanya_log_yang_melewati_batas_yang_dihapus_dalam_satu_kali_jalan(): void
    {
        $tua = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(40))
            ->trashedAt(Carbon::now()->subDays(30))
            ->create();

        $baru = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(10))
            ->trashedAt(Carbon::now()->subDays(3))
            ->create();

        $this->artisan('audit:purge')
            ->expectsOutputToContain('1 audit log dihapus permanen.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $tua->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $baru->id]);
    }
}