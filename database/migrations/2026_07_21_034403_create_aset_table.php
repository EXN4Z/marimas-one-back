<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migration 2026_07_21_034402_repair_aset_and_supplier_tables juga
        // bisa membuat tabel ini (khusus buat database lama yang tabelnya
        // sempat kehapus). Cek dulu biar gak nabrak "relation already exists"
        // kalau repair migration itu udah jalan lebih dulu.
        if (!Schema::hasTable('aset')) {
            Schema::create('aset', function (Blueprint $table) {
                $table->id();
                $table->string('kode_aset')->unique();
                $table->foreignId('jenis_id')->nullable()
                    ->constrained('jenis_aset')
                    ->nullOnDelete();
                $table->string('merek')->nullable();
                $table->string('tipe')->nullable();
                $table->string('warna')->nullable();
                $table->string('serial_number')->nullable()->unique();
                $table->string('perusahaan')->nullable();
                $table->text('keterangan')->nullable();
                $table->string('foto')->nullable();
                $table->foreignId('supplier_id')->nullable()
                    ->constrained('supplier')
                    ->nullOnDelete();
                $table->date('tanggal_pembelian')->nullable();
                $table->string('no_surat_jalan')->nullable();
                $table->string('no_good_receive')->nullable();
                $table->string('status')->default('tersedia'); // tersedia, dipakai, rusak, diperbaiki
                $table->timestamps();
            });
        }

        // Auto-generate kode_aset format: IT-2026-00001, mirip pola generate_kode_barang.
        // create-or-replace + drop-trigger-if-exists bikin blok ini aman
        // dijalankan berkali-kali (misal trigger-nya udah dibuat duluan
        // oleh repair migration).
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

            drop trigger if exists trg_generate_kode_aset on aset;

            create trigger trg_generate_kode_aset
            before insert on aset
            for each row
            execute function generate_kode_aset();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists trg_generate_kode_aset on aset');
        DB::unprepared('drop function if exists generate_kode_aset');
        Schema::dropIfExists('aset');
    }
};