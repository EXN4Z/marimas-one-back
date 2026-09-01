<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis Aset & Kategori dihapus total -- aset sekarang cukup diidentifikasi
     * lewat merek/tipe langsung (kolom "Jenis Aset" di form Tambah Aset
     * dihapus, label "Merek" diganti "Nama/Merek"). kode_aset yang tadinya
     * ambil dari jenis_aset.nama sekarang ambil dari kata pertama `merek`.
     *
     * Kelengkapan (Tas, Charger, dst) TIDAK terpengaruh migration ini --
     * tabel aset_kelengkapan sudah lebih dulu lepas dari jenis_id (lihat
     * migration drop_column_in_aset_kelengkapan_table) dan sudah nempel ke
     * aset induk lewat aset_id sejak migration add_aset_id_to_aset_kelengkapan_table.
     */
    public function up(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jenis_id');
        });

        Schema::dropIfExists('jenis_aset');
        Schema::dropIfExists('kategori');

        // kode_aset format: IT-{tahun}-{MEREK}-{nomor}, mis. IT-2026-ASUS-0001.
        // jenis_kode diganti jadi merek_kode: kata pertama dari kolom `merek`
        // di baris aset itu sendiri (bukan lagi lookup ke jenis_aset.nama,
        // tabelnya sudah tidak ada).
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_aset()
            returns trigger as $$
            declare
              tahun text;
              next_number integer;
              lock_key bigint;
              merek_kode text;
            begin
              if new.kode_aset is not null and new.kode_aset != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              merek_kode := upper(regexp_replace(
                coalesce(split_part(trim(new.merek), ' ', 1), 'LAIN'),
                '[^a-zA-Z0-9]', '', 'g'
              ));

              if merek_kode is null or merek_kode = '' then
                merek_kode := 'LAIN';
              end if;

              lock_key := hashtext('IT' || tahun || merek_kode);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_aset from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from aset
              where kode_aset like 'IT-' || tahun || '-' || merek_kode || '-%';

              new.kode_aset := 'IT-' || tahun || '-' || merek_kode || '-' || lpad(next_number::text, 4, '0');

              return new;
            end;
            $$ language plpgsql;
        SQL);
    }

    public function down(): void
    {
        Schema::create('kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('kode')->unique();
            $table->timestamps();
        });

        DB::table('kategori')->insert([
            ['nama' => 'Barang', 'kode' => 'aset_utama', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Kelengkapan', 'kode' => 'kelengkapan', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('jenis_aset', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->foreignId('kategori_id')->constrained('kategori')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('aset', function (Blueprint $table) {
            $table->foreignId('jenis_id')->nullable()
                ->constrained('jenis_aset')
                ->nullOnDelete();
        });

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
};