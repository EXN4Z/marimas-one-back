<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sebelumnya jenis_kode dibuat dari SELURUH kata di `nama` jenis
        // aset digabung tanpa spasi (mis. "Modem Telkomsel Orbit Exsp
        // Kudus" -> "MODEMTELKOMSELORBITEXSPKUDUS"), jadi kode_aset bisa
        // sangat panjang. Sekarang cuma KATA PERTAMA dari `nama` yang
        // dipakai (mis. "Modem Telkomsel Orbit Exsp Kudus" -> "MODEM"),
        // jadi kode_aset jadi lebih pendek: IT-2026-MODEM-0001.
        //
        // split_part(trim(nama), ' ', 1) ambil teks sebelum spasi pertama.
        // Kalau nama cuma 1 kata (tanpa spasi), split_part tetap
        // mengembalikan kata itu utuh -- jadi aman untuk semua kasus.
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
    }

    public function down(): void
    {
        // Rollback: kembalikan ke versi SEBELUMNYA (kata penuh, bukan
        // trigger/function dihapus total -- supaya down() tidak
        // menghilangkan fitur auto-generate kode_aset sama sekali).
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
                coalesce(nama, 'LAIN'),
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
    }
};