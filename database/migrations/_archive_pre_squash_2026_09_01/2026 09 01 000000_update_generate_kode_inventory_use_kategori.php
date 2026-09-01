<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lanjutan dari migration 2026_08_26_111347_update_generate_kode_inventory_use_nama.php
     * (yang ambil kategori_kode dari kata pertama kolom `nama` milik baris
     * inventory itu sendiri). Sekarang dibalik lagi jadi ambil dari
     * `kategori.nama` lewat join ke inventory.kategori_id -- BUKAN dari
     * `kategori.kode` (kolom itu sudah di-drop total di migration
     * 2026_08_26_124545_delete_kode_column_on_kategori_table.php), jadi
     * sumbernya kolom `nama` yang ada di tabel kategori.
     *
     * Karena nama function & nama trigger tidak berubah
     * (generate_kode_inventory / trg_generate_kode_inventory), tidak perlu
     * drop trigger dulu -- cukup create-or-replace function-nya, trigger
     * yang sudah terpasang otomatis pakai versi terbaru.
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
              kategori_kode text;
            begin
              if new.kode_inventory is not null and new.kode_inventory != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              select upper(regexp_replace(
                coalesce(nama, 'LAIN'),
                '[^a-zA-Z0-9]', '', 'g'
              ))
              into kategori_kode
              from kategori
              where id = new.kategori_id;

              if kategori_kode is null or kategori_kode = '' then
                kategori_kode := 'LAIN';
              end if;

              lock_key := hashtext('IT' || tahun || kategori_kode);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_inventory from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from inventory
              where kode_inventory like 'IT-' || tahun || '-' || kategori_kode || '-%';

              new.kode_inventory := 'IT-' || tahun || '-' || kategori_kode || '-' || lpad(next_number::text, 4, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }

    /**
     * Rollback: kembalikan ke versi SEBELUMNYA (kategori_kode diambil dari
     * kata pertama kolom `nama` di baris inventory itu sendiri, bukan dari
     * tabel kategori).
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_inventory()
            returns trigger as $$
            declare
              tahun text;
              next_number integer;
              lock_key bigint;
              kategori_kode text;
            begin
              if new.kode_inventory is not null and new.kode_inventory != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              kategori_kode := upper(regexp_replace(
                coalesce(split_part(trim(new.nama), ' ', 1), 'LAIN'),
                '[^a-zA-Z0-9]', '', 'g'
              ));

              if kategori_kode is null or kategori_kode = '' then
                kategori_kode := 'LAIN';
              end if;

              lock_key := hashtext('IT' || tahun || kategori_kode);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_inventory from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from inventory
              where kode_inventory like 'IT-' || tahun || '-' || kategori_kode || '-%';

              new.kode_inventory := 'IT-' || tahun || '-' || kategori_kode || '-' || lpad(next_number::text, 4, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }
};