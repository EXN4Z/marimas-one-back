<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- bikin native enum type
            create type user_role as enum ('guest', 'karyawan', 'manajer', 'hr', 'admin');

            -- hapus check constraint lama
            alter table users drop constraint if exists users_role_check;

            -- ubah kolom role pake enum type baru
            alter table users
              alter column role drop default,
              alter column role type user_role using role::user_role,
              alter column role set default 'karyawan';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table users
              alter column role drop default,
              alter column role type varchar(255) using role::text,
              alter column role set default 'karyawan';

            alter table users
              add constraint users_role_check
              check (role in ('guest', 'karyawan', 'manajer', 'hr', 'admin'));

            drop type if exists user_role;
        SQL);
    }
};