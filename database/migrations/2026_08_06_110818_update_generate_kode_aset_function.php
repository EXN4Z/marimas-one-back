<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.generate_kode_aset()
             RETURNS trigger
             LANGUAGE plpgsql
            AS $function$
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

                  -- ambil dari kolom `kode` (singkatan resmi kategori) dulu;
                  -- kalau kosong (jenis lama yang belum diisi kode-nya),
                  -- fallback ke potongan dari `nama` biar tidak sampai gagal.
                  select upper(regexp_replace(
                    coalesce(nullif(kode, ''), left(coalesce(nama, 'LAIN'), 6)),
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
                $function$
        SQL);
    }

    public function down(): void
    {
        // kembalikan ke versi lama (5 digit, ambil dari `nama` penuh) kalau perlu rollback
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.generate_kode_aset()
             RETURNS trigger
             LANGUAGE plpgsql
            AS $function$
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
                $function$
        SQL);
    }
};