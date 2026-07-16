<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pengajuan_izin ALTER COLUMN jenis_izin TYPE VARCHAR(255) USING jenis_izin::text');
        DB::statement('ALTER TABLE pengajuan_izin DROP CONSTRAINT IF EXISTS pengajuan_izin_jenis_izin_check');
    }

    public function down(): void
    {
        // Tidak perlu dipulihkan karena migrasi ini hanya membersihkan constraint lama.
    }
};
