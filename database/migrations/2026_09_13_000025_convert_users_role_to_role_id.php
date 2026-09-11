<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Ganti users.role (varchar, dicocokkan manual ke roles.nama) jadi
// users.role_id (FK asli ke roles.id). Liat INSTRUKSI-migrasi-role-fk.md
// buat konteks lengkap kenapa & apa aja yang ikut berubah di kode.
return new class extends Migration
{
    public function up(): void
    {
        // 1. Kolom baru, nullable dulu (biar bisa diisi backfill sebelum
        //    dipasang constraint NOT NULL).
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')
                ->constrained('roles')->restrictOnDelete();
        });

        // 2. Backfill: cocokkan users.role (string lama) ke roles.nama.
        DB::statement('
            UPDATE users
            SET role_id = roles.id
            FROM roles
            WHERE users.role = roles.nama
        ');

        // 3. Guard: kalau ada user yang role stringnya gak cocok ke roles
        //    manapun (data kotor/typo lama), STOP migration di sini
        //    daripada lanjut dengan role_id NULL yang bakal lolos diam-diam.
        $orphan = DB::table('users')->whereNull('role_id')->count();
        if ($orphan > 0) {
            throw new \RuntimeException(
                "Migration dibatalkan: ada {$orphan} user yang kolom role-nya ".
                "gak cocok ke tabel roles manapun. Cek dulu: ".
                "SELECT id, name, role FROM users WHERE role_id IS NULL;"
            );
        }

        // 4. Baru sekarang aman di-NOT NULL-kan, dan kolom role lama dibuang.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->nullable()->after('role_id');
        });

        DB::statement('
            UPDATE users
            SET role = roles.nama
            FROM roles
            WHERE users.role_id = roles.id
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->nullable(false)->default('karyawan')->change();
            $table->dropConstrainedForeignId('role_id');
        });
    }
};