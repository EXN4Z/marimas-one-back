<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_barang()
            returns trigger as $$
            declare
              prefix text;
              tahun text;
              next_number integer;
              kategori_nama text;
              lock_key bigint;
            begin
              if new.kode_barang is not null and new.kode_barang != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              if new.kategori_id is not null then
                select upper(left(nama, 3)) into kategori_nama
                from kategori_barang
                where id = new.kategori_id;
              end if;

              prefix := coalesce(kategori_nama, 'GEN');

              -- lock per kombinasi kategori + tahun, biar aman dari insert bersamaan
              lock_key := hashtext(prefix || tahun);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_barang from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from barang
              where kode_barang like prefix || '-' || tahun || '-%';

              new.kode_barang := prefix || '-' || tahun || '-' || lpad(next_number::text, 5, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_barang()
            returns trigger as $$
            begin
              if new.kode_barang is null or new.kode_barang = '' then
                new.kode_barang := 'BRG-' || lpad(nextval('barang_kode_seq')::text, 5, '0');
              end if;
              return new;
            end;
            $$ language plpgsql;
        SQL);
    }
};