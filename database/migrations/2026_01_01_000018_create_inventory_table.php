<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED. Riwayat aslinya: tabel ini dulu bernama `aset`
 * (id, kode_aset, jenis_id -> jenis_aset, dst), lewat banyak tahap
 * add/drop kolom, di-rename jadi `inventory`, sempat 2 tingkat
 * kategori (kategori + master_kategori), sampai akhirnya disederhanakan
 * jadi 1 tingkat (kategori_id langsung) seperti sekarang -- lihat
 * App\Models\MasterData\Inventory untuk daftar kolom final yang dipakai
 * aplikasi.
 *
 * kategori_id sengaja langsung NOT NULL dari awal (bukan nullable lalu
 * di-backfill+diubah lewat migration terpisah seperti riwayat aslinya --
 * riwayat itu bahkan sempat salah urutan sampai error kalau di-replay
 * dari kosong, makanya di-squash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->string('kode_inventory')->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained('inventory')
                ->nullOnDelete();
            $table->foreignId('kategori_id')
                ->constrained('kategori')
                ->restrictOnDelete();

            $table->string('nama')->nullable();
            $table->string('warna')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->unsignedInteger('jumlah')->default(1);

            $table->string('no_bukti')->nullable();
            $table->date('tanggal')->nullable();
            $table->string('nik')->nullable();
            $table->string('penerima')->nullable();

            $table->date('tanggal_garansi')->nullable();
            $table->date('tanggal_input')->nullable();
            $table->date('tanggal_invoice')->nullable();
            $table->string('merk', 100)->nullable();
            $table->string('type', 100)->nullable();

            $table->string('perusahaan')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('diterima_oleh')->nullable();
            $table->string('diketahui')->nullable();
            $table->string('dibuat_oleh')->nullable();
            $table->string('diketahui_hrd')->nullable();
            $table->string('foto')->nullable();

            $table->foreignId('supplier_id')->nullable()
                ->constrained('supplier')
                ->nullOnDelete();
            $table->string('no_surat_jalan')->nullable();
            $table->string('no_good_receive')->nullable();

            $table->string('status')->default('tersedia'); // tersedia, dipakai, rusak, diperbaiki
            $table->dateTime('tanggal_rusak')->nullable();

            $table->timestamps();
        });

        // Auto-generate kode_inventory format: IT-{tahun}-{KATEGORI}-{nomor},
        // mis. IT-2026-LAPTOP-0027. Ini versi TERAKHIR dari fungsi ini
        // (kategori_kode diambil dari kategori.nama lewat kategori_id).
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

            drop trigger if exists trg_generate_kode_inventory on inventory;

            create trigger trg_generate_kode_inventory
            before insert on inventory
            for each row
            execute function generate_kode_inventory();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists trg_generate_kode_inventory on inventory');
        DB::unprepared('drop function if exists generate_kode_inventory()');
        Schema::dropIfExists('inventory');
    }
};