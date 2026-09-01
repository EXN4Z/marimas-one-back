<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->date('tanggal_garansi')->nullable();
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

        // Auto-generate kode_aset format: IT-{tahun}-{JENIS}-{nomor}, contoh
        // IT-2026-LAPTOP-0027. Ini versi TERAKHIR dari fungsi ini (sempat
        // diubah beberapa kali di riwayat migrasi lama -- lihat
        // update_generate_kode_aset_per_jenis & update_generate_kode_aset_nama).
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
