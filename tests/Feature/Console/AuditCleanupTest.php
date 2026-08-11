<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_lebih_dari_1_minggu_dipindahkan_ke_trash(): void
    {
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(8))
            ->create();

        $this->artisan('audit:cleanup')
            ->expectsOutputToContain('1 audit log dipindahkan ke trash.')
            ->assertExitCode(0);

        $this->assertSoftDeleted($log);
    }

    public function test_log_tepat_di_batas_1_minggu_belum_ditrash(): void
    {
        // created_at kurang dari 7 hari (mis. 6 hari 23 jam) -> belum kena.
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(6)->subHours(23))
            ->create();

        $this->artisan('audit:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'deleted_at' => null,
        ]);
    }

    public function test_log_kurang_dari_1_minggu_tidak_ikut_ditrash(): void
    {
        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(2))
            ->create();

        $this->artisan('audit:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'deleted_at' => null,
        ]);
    }

    public function test_log_yang_sudah_ditrash_tidak_diproses_ulang(): void
    {
        $waktuTrashSebelumnya = Carbon::now()->subDays(5);

        $log = AuditLog::factory()
            ->createdAt(Carbon::now()->subDays(10))
            ->trashedAt($waktuTrashSebelumnya)
            ->create();

        $this->artisan('audit:cleanup')
            ->expectsOutputToContain('0 audit log dipindahkan ke trash.')
            ->assertExitCode(0);

        $this->assertEquals(
            $waktuTrashSebelumnya->toDateTimeString(),
            $log->fresh()->deleted_at->toDateTimeString()
        );
    }

    public function test_hanya_log_yang_melewati_batas_yang_diproses_dalam_satu_kali_jalan(): void
    {
        $tua = AuditLog::factory()->createdAt(Carbon::now()->subDays(10))->create();
        $baru = AuditLog::factory()->createdAt(Carbon::now()->subDays(1))->create();

        $this->artisan('audit:cleanup')
            ->expectsOutputToContain('1 audit log dipindahkan ke trash.')
            ->assertExitCode(0);

        $this->assertSoftDeleted($tua);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $baru->id,
            'deleted_at' => null,
        ]);
    }
}