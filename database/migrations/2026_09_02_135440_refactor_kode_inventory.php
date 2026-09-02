<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
            DB::unprepared(<<<'SQL'
        create or replace function generate_kode_inventory()
        returns trigger as $$
        declare
        tahun text;
        next_number integer;
        lock_key bigint;
        begin
        if new.kode_inventory is not null and new.kode_inventory != '' then
            return new;
        end if;

        tahun := to_char(now(), 'YY');

        lock_key := hashtext('IT' || tahun);
        perform pg_advisory_xact_lock(lock_key);

        select coalesce(max(
            substring(kode_inventory from '(\d+)$')::integer
        ), 0) + 1
        into next_number
        from inventory
        where kode_inventory like 'IT-' || tahun || '-%';

        new.kode_inventory := 'IT-' || tahun || '-' || lpad(next_number::text, 5, '0');

        return new;
        end;
        $$ language plpgsql;

        drop trigger if exists trg_generate_kode_inventory on inventory;

        create trigger trg_generate_kode_inventory
        before insert on inventory
        for each row
        execute function generate_kode_inventory();
    SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
