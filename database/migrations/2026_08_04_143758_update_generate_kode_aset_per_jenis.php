<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update format kode_aset jadi IT-{tahun}-{JENIS}-{nomor}, contoh:
        // Laptop  -> IT-2026-LAPTOP-00027
        // Keyboard-> IT-2026-KEYBOARD-00027
        // Nomor urut dihitung terpisah per kombinasi tahun + jenis.
        // Nama jenis diambil dari tabel jenis_aset, diseragamkan jadi huruf
        // besar tanpa spasi/simbol biar aman dipakai di kode (mis. "Kabel HDMI" -> "KABELHDMI").
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

              select upper(regexp_replace(coalesce(nama, 'LAIN'), '[^a-zA-Z0-9]', '', 'g'))
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

              new.kode_aset := 'IT-' || tahun || '-' || jenis_kode || '-' || lpad(next_number::text, 5, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Balikin ke format lama IT-{tahun}-{nomor} tanpa jenis.
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_aset()
            returns trigger as $$
            declare
              tahun text;
              next_number integer;
              lock_key bigint;
            begin
              if new.kode_aset is not null and new.kode_aset != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              lock_key := hashtext('IT' || tahun);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_aset from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from aset
              where kode_aset like 'IT-' || tahun || '-%';

              new.kode_aset := 'IT-' || tahun || '-' || lpad(next_number::text, 5, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }
};