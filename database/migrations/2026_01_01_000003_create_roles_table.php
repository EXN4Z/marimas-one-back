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
 * PALING AWAL (sebelum `users`) dan langsung diisi role default di
 * sini juga -- `users` (lihat migration berikutnya) langsung FK ke
 * sini sejak awal, gak pernah lewat kolom enum/varchar `role` sama
 * sekali.
 *
 * REVISI (hapus level & label): dulu tabel ini punya kolom `label`
 * (nama tampilan) & `level` (dipakai App\Models\User::roleLevel()/
 * hasRoleAtLeast() buat hierarki akses lintas role). Sekarang hak akses
 * disederhanakan jadi cuma 2 tingkat: 'admin' (akses semua) vs role lain
 * (semuanya setara persis kayak 'karyawan', lihat
 * App\Http\Middleware\EnsureUserIsMember) -- jadi `level` gak lagi
 * berarti apa-apa, dan `label` gak pernah dipakai di UI (Master Data
 * Role cuma nampilin `nama`). Kedua kolom dihapus dari sini.
 *
 * `nama` dipakai App\Models\User::isAdmin() (cek `nama === 'admin'`)
 * buat nentuin hak akses -- lihat catatan lengkap di User.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->timestamps();
        });

        // REVISI (simplify_roles_table): dulu 6 role default (guest,
        // karyawan, cabang, manajer, hr, admin), disederhanain jadi cuma 3
        // -- 'karyawan'/'manajer'/'hr'/'guest' digabung jadi 'user' (gak
        // pernah beda akses sama sekali, lihat catatan REVISI di atas).
        // 'cabang' sengaja dibiarin, belum digarap (lihat migration
        // 2026_09_15_000000_simplify_roles_table). Instance yang udah
        // ke-migrate dengan 6 role lama otomatis ke-merge lewat migration
        // itu, gak perlu backfill manual di sini.
        $defaults = ['user', 'cabang', 'admin'];

        foreach ($defaults as $nama) {
            DB::table('roles')->insert([
                'nama'       => $nama,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};