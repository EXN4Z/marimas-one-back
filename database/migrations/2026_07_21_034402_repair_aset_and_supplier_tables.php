<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration pembersihan sebelumnya (2026_07_21_034100) ternyata men-drop
     * tabel 'aset' & 'supplier' versi BARU yang sudah berhasil dibuat di deploy
     * sebelumnya (migration create-nya sudah tercatat "Ran" jadi gak dijalankan
     * ulang). Akibatnya kedua tabel itu hilang permanen. Migration ini bikin
     * ulang -- tapi HANYA kalau memang belum ada, jadi aman dijalankan di
     * kondisi database apapun (baik yang kena masalah ini maupun yang bersih).
     */
    public function up(): void
    {
        if (!Schema::hasTable('supplier')) {
            Schema::create('supplier', function (Blueprint $table) {
                $table->id();
                $table->string('nama')->unique();
                $table->string('alamat')->nullable();
                $table->string('telepon')->nullable();
                $table->timestamps();
            });
        }

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
                $table->string('status')->default('tersedia');
                $table->timestamps();
            });
        }

        // Trigger auto-generate kode_aset. create-or-replace + drop-if-exists
        // jadi aman dijalankan berkali-kali.
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
        // Sengaja gak di-drop lagi di sini biar gak kejadian yang sama.
    }
};
