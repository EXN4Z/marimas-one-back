<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED. Riwayat aslinya: tabel `roles` dibuat belakangan
 * (setelah `users` sudah lama ada dengan kolom `role` native Postgres
 * enum), baru di-seed 6 role default lewat migration terpisah
 * (convert_users_role_to_varchar_and_seed_roles), lalu users.role
 * di-convert ke users.role_id FK lewat migration terpisah lagi
 * (convert_users_role_to_role_id). Karena fresh install gak punya data
 * lama yang perlu di-backfill, tabel ini sekarang dipindah ke URUTAN
 * PALING AWAL (sebelum `users`) dan langsung diisi 6 role default di
 * sini juga -- `users` (lihat migration berikutnya) langsung FK ke
 * sini sejak awal, gak pernah lewat kolom enum/varchar `role` sama
 * sekali.
 *
 * `nama` dipakai App\Models\User::roleLevel()/hasRoleAtLeast() buat
 * nentuin hak akses lintas role -- lihat catatan lengkap di User.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->string('label', 100)->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->timestamps();
        });

        // Level disamain persis App\Models\User (dulu $roleLevels hardcode,
        // sekarang murni data tabel ini).
        $defaults = [
            ['nama' => 'guest', 'label' => 'Guest', 'level' => 0],
            ['nama' => 'karyawan', 'label' => 'Karyawan', 'level' => 1],
            ['nama' => 'cabang', 'label' => 'Cabang', 'level' => 1],
            ['nama' => 'manajer', 'label' => 'Manajer', 'level' => 1],
            ['nama' => 'hr', 'label' => 'HR', 'level' => 1],
            ['nama' => 'admin', 'label' => 'Admin', 'level' => 5],
        ];

        foreach ($defaults as $role) {
            DB::table('roles')->insert(
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
