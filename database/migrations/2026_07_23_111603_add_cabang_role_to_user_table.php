<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // WAJIB: matikan transaction wrapper, karena ALTER TYPE ... ADD VALUE
    // tidak boleh dijalankan di dalam transaction block di PostgreSQL.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'cabang'");
    }

    public function down(): void
    {
        // PostgreSQL tidak mendukung menghapus value dari enum type secara langsung.
        // Kalau perlu rollback total, harus buat enum baru lalu migrasikan datanya:
        //
        // DB::statement("ALTER TYPE user_role RENAME TO user_role_old");
        // DB::statement("CREATE TYPE user_role AS ENUM ('guest', 'karyawan', 'hr', 'manajer', 'admin')");
        // DB::statement("ALTER TABLE users ALTER COLUMN role TYPE user_role USING role::text::user_role");
        // DB::statement("DROP TYPE user_role_old");
        //
        // Pastikan dulu tidak ada user dengan role 'cabang' sebelum menjalankan ini,
        // kalau masih ada baris dengan role='cabang', baris ke-3 di atas akan gagal.
    }
};