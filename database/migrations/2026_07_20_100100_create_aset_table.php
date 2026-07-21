<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset', function (Blueprint $table) {
            $table->id();
            // Nullable: aset boleh berdiri sendiri tanpa dikaitkan ke produk di
            // katalog barang, tapi kalau ada, dia ikut nongol di daftar Inventaris.
            $table->foreignId('barang_id')->nullable()->constrained('barang')->nullOnDelete();
            $table->string('kode_aset')->unique();
            $table->string('serial_number')->nullable();
            $table->string('merk')->nullable();
            $table->string('tipe')->nullable();
            $table->string('warna')->nullable();
            // Perusahaan pemilik/klien (mis. kode "mpk", "uth"), disimpan bebas
            // teks dulu -- belum ada tabel perusahaan terpisah.
            $table->string('perusahaan')->nullable();
            $table->enum('status', ['tersedia', 'dipinjam', 'diperbaiki', 'dihapus'])->default('tersedia');
            $table->enum('kondisi', ['baik', 'rusak_ringan', 'rusak_berat'])->default('baik');
            $table->string('supplier')->nullable();
            $table->string('no_surat_jalan')->nullable();
            $table->string('no_good_receive')->nullable();
            $table->date('tanggal_pembelian')->nullable();
            $table->string('foto')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        // Auto-generate kode_aset kayak generate_kode_barang, tapi prefix tetap
        // "AST" (gak per kategori) karena aset gak selalu punya barang_id.
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
              lock_key := hashtext('AST' || tahun);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_aset from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from aset
              where kode_aset like 'AST-' || tahun || '-%';

              new.kode_aset := 'AST-' || tahun || '-' || lpad(next_number::text, 5, '0');

              return new;
            end;
            $$ language plpgsql;

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
