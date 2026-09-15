<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sederhanain role: dari 6 value (guest, karyawan, cabang, manajer, hr,
 * admin) jadi cuma 2 (admin, user) -- karena hak akses emang cuma 2
 * tingkat sejak REVISI di create_roles_table (lihat catatan di sana),
 * jadi 'karyawan'/'manajer'/'hr'/'guest' cuma beda nama doang tanpa beda
 * akses sama sekali. Info jabatan/departemen asli sekarang cukup
 * diwakilin kolom users.departemen_id yang udah ada.
 *
 * CABANG SENGAJA DIBIARIN APA ADANYA buat sementara -- dia beda dari 4
 * role lain karena beneran punya efek di kode (lihat users.lokasi_kantor_id,
 * DashboardController, LokasiKantor::karyawan(), UserController). Dipikirin
 * & dibongkar terpisah belakangan, bukan bagian dari migration ini.
 *
 * Strategi:
 *   1. Role 'karyawan' dipakai sebagai row yang di-rename jadi 'user'
 *      (dia udah pasti ada dari seed default, dan biasanya row yang
 *      paling banyak dipakai user -- lebih murah rename dia daripada
 *      bikin row baru lalu mindahin semua user ke situ).
 *   2. Semua user yang role_id-nya nunjuk ke 'manajer'/'hr'/'guest'
 *      dipindah nunjuk ke role 'user' (hasil rename di langkah 1).
 *   3. Role 'manajer', 'hr', 'guest' dihapus (udah gak ada user yang
 *      makai setelah langkah 2 -- aman dihapus, gak nabrak FK
 *      restrictOnDelete di users.role_id).
 *   4. 'admin' & 'cabang' gak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $userRoleId = DB::table('roles')->where('nama', 'karyawan')->value('id');

            // Fresh install yang migration create_roles_table-nya udah keburu
            // diedit duluan buat langsung seed 'user' (bukan 'karyawan') --
            // gak ada yang perlu dikerjain lagi di sini.
            if (!$userRoleId) {
                return;
            }

            DB::table('roles')->where('id', $userRoleId)->update(['nama' => 'user']);

            $rolesToMerge = DB::table('roles')
                ->whereIn('nama', ['manajer', 'hr', 'guest'])
                ->pluck('id');

            if ($rolesToMerge->isNotEmpty()) {
                DB::table('users')
                    ->whereIn('role_id', $rolesToMerge)
                    ->update(['role_id' => $userRoleId]);

                DB::table('roles')->whereIn('id', $rolesToMerge)->delete();
            }
        });
    }

    public function down(): void
    {
        // Gak beneran reversible (info role asli tiap user yang di-merge ke
        // 'user' udah hilang, gak bisa dipulihin ke karyawan/manajer/hr/guest
        // yang mana). Down cuma balikin row 'user' jadi nama 'karyawan' lagi,
        // 3 role lain yang dihapus TIDAK dibikin ulang.
        DB::table('roles')->where('nama', 'user')->update(['nama' => 'karyawan']);
    }
};