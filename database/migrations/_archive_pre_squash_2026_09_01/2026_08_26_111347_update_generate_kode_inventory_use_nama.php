<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Lanjutan dari migration sebelumnya (generate_kode_inventory,
        // yang ambil kategori_kode dari join ke master_kategori lewat
        // master_kategori_id). Sekarang sumber kategori_kode diubah jadi
        // ambil langsung dari kata pertama kolom `nama` milik baris
        // inventory itu sendiri (split_part(trim(new.nama), ' ', 1)),
        // jadi TIDAK PERLU lagi query/join ke master_kategori. Ini
        // mengikuti pola yang sama seperti migration
        // 2026_08_12_083609_update_generate_kode_aset.php sebelumnya,
        // hanya beda tabel & kolom sumbernya.
        //
        // Karena nama function & nama trigger tidak berubah
        // (generate_kode_inventory / trg_generate_kode_inventory), tidak
        // perlu drop trigger dulu -- cukup create-or-replace function-nya,
        // trigger yang sudah terpasang otomatis pakai versi terbaru.
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

    public function down(): void
    {
        // Rollback: kembalikan ke versi SEBELUMNYA (kategori_kode diambil
        // dari join ke master_kategori lewat master_kategori_id, bukan
        // dari kolom nama di baris inventory itu sendiri).
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
                coalesce(kode, 'LAIN'),
                '[^a-zA-Z0-9]', '', 'g'
              ))
              into kategori_kode
              from master_kategori
              where id = new.master_kategori_id;

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