<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FIX: kolom users.role ternyata masih native Postgres enum (dibikin di
// 2026_01_01_000003_create_users_table.php: "create type user_role as
// enum (...)"), padahal fitur Role (Master Data > Role) di atasnya
// diasumsikan users.role udah plain string (lihat catatan salah di
// 2026_09_11_000000_create_roles_table.php: "kolom users.role masih
// plain string, BUKAN foreign key"). Akibatnya dua bug:
//
// 1. GET /api/role (Role::withCount('users')) selalu 500 -- Postgres
//    nolak bandingin kolom enum (users.role) sama kolom varchar
//    (roles.nama) tanpa cast eksplisit ("operator does not exist:
//    user_role = character varying").
// 2. Role baru yang dibikin admin lewat halaman Role gak akan PERNAH
//    bisa dipakein ke user manapun -- insert/update bakal ditolak
//    Postgres karena valuenya bukan salah satu dari 6 value tetap yang
//    di-hardcode di enum user_role saat tabel users dibuat.
//
// Migration ini convert kolom users.role jadi varchar biasa (drop tipe
// enum-nya total) supaya role baru beneran bisa dipakai, dan isi tabel
// `roles` dengan 6 role bawaan (kalau belum ada -- pakai updateOrInsert
// biar aman dijalanin di DB yang mungkin udah sempet diisi manual lewat
// UI Role) supaya validasi `exists:roles,nama` di UserController gak
// langsung nolak semua role lama begitu diaktifkan.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN role TYPE VARCHAR(50) USING role::text');
        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'karyawan'");
        DB::statement('DROP TYPE IF EXISTS user_role');

        // Level disamain persis App\Models\User::$roleLevels.
        $defaults = [
            ['nama' => 'guest', 'label' => 'Guest', 'level' => 0],
            ['nama' => 'karyawan', 'label' => 'Karyawan', 'level' => 1],
            ['nama' => 'cabang', 'label' => 'Cabang', 'level' => 1],
            ['nama' => 'manajer', 'label' => 'Manajer', 'level' => 1],
            ['nama' => 'hr', 'label' => 'HR', 'level' => 1],
            ['nama' => 'admin', 'label' => 'Admin', 'level' => 5],
        ];

        foreach ($defaults as $role) {
            DB::table('roles')->updateOrInsert(
                ['nama' => $role['nama']],
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::statement("CREATE TYPE user_role AS ENUM ('guest', 'karyawan', 'manajer', 'hr', 'admin', 'cabang')");
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN role TYPE user_role USING role::user_role');
        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'karyawan'");
    }
};