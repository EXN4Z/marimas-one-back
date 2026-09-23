<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hapus role 'cabang' dari sistem.
 * User yang masih memakai role tersebut dipindah ke role 'user'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $cabangRoleId = DB::table('roles')->where('nama', 'cabang')->value('id');
            if (!$cabangRoleId) {
                return;
            }

            $userRoleId = DB::table('roles')->where('nama', 'user')->value('id');
            if (!$userRoleId) {
                $userRoleId = DB::table('roles')->insertGetId([
                    'nama' => 'user',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('users')
                ->where('role_id', $cabangRoleId)
                ->update(['role_id' => $userRoleId]);

            DB::table('roles')->where('id', $cabangRoleId)->delete();
        });
    }

    public function down(): void
    {
        $exists = DB::table('roles')->where('nama', 'cabang')->exists();
        if ($exists) {
            return;
        }

        DB::table('roles')->insert([
            'nama' => 'cabang',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
