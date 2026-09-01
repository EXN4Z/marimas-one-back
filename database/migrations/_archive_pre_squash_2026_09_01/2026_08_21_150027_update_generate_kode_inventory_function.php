<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Lanjutan dari migration sebelumnya (generate_kode_aset, yang
        // ambil kata pertama dari nama jenis_aset). Sekarang tabel aset
        // + jenis_aset sudah jadi inventory + master_kategori, dan
        // master_kategori punya kolom `kode` sendiri (mis. "LAPTOP",
        // "CHRG") yang memang disiapkan khusus buat generate kode --
        // jadi TIDAK PERLU lagi di-split_part dari `nama`, tinggal pakai
        // master_kategori.kode langsung. Ini juga otomatis menyatukan
        // format kode barang utama & kelengkapan (dulu kode_aset vs
        // kode_kelengkapan terpisah), karena keduanya sekarang sama-sama
        // baris `inventory` dengan master_kategori_id, jadi lewat trigger
        // yang sama.
        //
        // Nama function sengaja diganti jadi generate_kode_inventory
        // (bukan cuma create-or-replace nama lama) supaya nama function
        // konsisten dengan nama tabel/kolom barunya. Function lama
        // (generate_kode_aset) di-drop di akhir up().
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

        // Pasang trigger baru di tabel inventory (kalau trigger lama
        // 'trg_generate_kode_aset' masih nempel di tabel aset/inventory
        // dari migration sebelumnya, drop dulu supaya gak dobel-jalan).
        DB::unprepared('drop trigger if exists trg_generate_kode_aset on inventory');
        DB::unprepared('drop trigger if exists trg_generate_kode_inventory on inventory');
        DB::unprepared(<<<'SQL'
            create trigger trg_generate_kode_inventory
            before insert on inventory
            for each row
            execute function generate_kode_inventory();
        SQL);

        // Function lama sudah tidak dipakai trigger manapun -- drop
        // supaya tidak menumpuk function basi di database.
        DB::unprepared('drop function if exists generate_kode_aset()');
    }

    public function down(): void
    {
        // Rollback: kembalikan ke versi SEBELUMNYA (generate_kode_aset,
        // ambil kata pertama dari nama jenis_aset, tabel aset) -- fungsi
        // ini dipulihkan persis seperti sebelum migration ini dijalankan,
        // bukan dihapus total, supaya down() tidak menghilangkan fitur
        // auto-generate kode sama sekali.
        DB::unprepared('drop trigger if exists trg_generate_kode_inventory on inventory');
        DB::unprepared('drop function if exists generate_kode_inventory()');

        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_aset()
            returns trigger as $$
            declare
              tahun text;
              next_number integer;
              lock_key bigint;
              jenis_kode text;
            begin
              if new.kode_aset is not null and new.kode_aset != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              select upper(regexp_replace(
                coalesce(split_part(trim(nama), ' ', 1), 'LAIN'),
                '[^a-zA-Z0-9]', '', 'g'
              ))
              into jenis_kode
              from jenis_aset
              where id = new.jenis_id;

              if jenis_kode is null or jenis_kode = '' then
                jenis_kode := 'LAIN';
              end if;

              lock_key := hashtext('IT' || tahun || jenis_kode);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_aset from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from aset
              where kode_aset like 'IT-' || tahun || '-' || jenis_kode || '-%';

              new.kode_aset := 'IT-' || tahun || '-' || jenis_kode || '-' || lpad(next_number::text, 4, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            create trigger trg_generate_kode_aset
            before insert on aset
            for each row
            execute function generate_kode_aset();
        SQL);
    }
};