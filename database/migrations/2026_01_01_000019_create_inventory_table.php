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
 *
 * SQUASH KE-2 (Sept 2026): sederet migration add/drop kolom kecil-kecilan
 * di atas versi pertama sudah diserap balik ke sini supaya tabel ini
 * langsung jadi dalam bentuk akhirnya, bukan lagi lewat banyak langkah
 * kecil yang gantian nambah lalu hapus kolom:
 * - Kolom tanggal, nik, penerima, diterima_oleh, diketahui, dibuat_oleh,
 *   diketahui_hrd TIDAK ikut dibuat lagi (dulu ada, langsung di-drop lagi
 *   tak lama setelahnya -- gak pernah kepakai di final state, data
 *   sejenis itu buat form import Excel cuma numpang lewat variable
 *   sementara, lihat App\Imports\InventoryBuktiImport).
 * - Kolom `perusahaan` (string bebas) diganti langsung jadi `perusahaan_id`
 *   (foreign key ke tabel perusahaan) -- dulu nambah kolom teks dulu,
 *   belakangan baru diganti relasi FK lewat 2 migration terpisah.
 * - Kolom tanggal_rusak TIDAK ikut dibuat lagi (dulu ada, di-drop lagi
 *   tak lama setelahnya, gak pernah dipakai form manapun di frontend).
 * - Fungsi generate_kode_inventory() langsung didefinisikan dalam bentuk
 *   FINAL-nya (format IT-{YY}-{nomor 5 digit}, tanpa kode kategori) --
 *   versi pertama yang sempat menyelipkan kode kategori di tengah format
 *   sudah tidak dipakai lagi.
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

            $table->date('tanggal_garansi')->nullable();
            $table->date('tanggal_input')->nullable();
            $table->date('tanggal_invoice')->nullable();
            $table->string('merk', 100)->nullable();
            $table->string('type', 100)->nullable();

            $table->foreignId('perusahaan_id')->nullable()
                ->constrained('perusahaan')
                ->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->string('foto')->nullable();

            $table->foreignId('supplier_id')->nullable()
                ->constrained('supplier')
                ->nullOnDelete();
            $table->string('no_surat_jalan')->nullable();
            $table->string('no_good_receive')->nullable();

            $table->string('status')->default('tersedia'); // tersedia, dipakai, menunggu_perbaikan, diperbaiki, rusak_berat

            $table->timestamps();
        });

        // Auto-generate kode_inventory format: IT-{YY}-{nomor 5 digit},
        // mis. IT-26-00027.
        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_inventory()
            returns trigger as $$
            declare
              tahun text;
              next_number integer;
              lock_key bigint;
            begin
              if new.kode_inventory is not null and new.kode_inventory != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YY');

              lock_key := hashtext('IT' || tahun);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_inventory from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from inventory
              where kode_inventory like 'IT-' || tahun || '-%';

              new.kode_inventory := 'IT-' || tahun || '-' || lpad(next_number::text, 5, '0');

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
