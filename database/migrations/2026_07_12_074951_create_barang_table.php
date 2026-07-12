<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang', function (Blueprint $table) {
            $table->id();
            $table->string('kode_barang')->unique();
            $table->string('nama');
            $table->foreignId('kategori_id')->nullable()
                ->constrained('kategori_barang')
                ->nullOnDelete();
            $table->string('satuan')->default('pcs');
            $table->integer('stok')->default(0);
            $table->integer('stok_minimum')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            create or replace function generate_kode_barang()
            returns trigger as $$
            declare
              prefix text;
              tahun text;
              next_number integer;
              kategori_nama text;
              lock_key bigint;
            begin
              if new.kode_barang is not null and new.kode_barang != '' then
                return new;
              end if;

              tahun := to_char(now(), 'YYYY');

              if new.kategori_id is not null then
                select upper(left(nama, 3)) into kategori_nama
                from kategori_barang
                where id = new.kategori_id;
              end if;

              prefix := coalesce(kategori_nama, 'GEN');

              lock_key := hashtext(prefix || tahun);
              perform pg_advisory_xact_lock(lock_key);

              select coalesce(max(
                substring(kode_barang from '(\d+)$')::integer
              ), 0) + 1
              into next_number
              from barang
              where kode_barang like prefix || '-' || tahun || '-%';

              new.kode_barang := prefix || '-' || tahun || '-' || lpad(next_number::text, 5, '0');

              return new;
            end;
            $$ language plpgsql;

            create trigger trg_generate_kode_barang
            before insert on barang
            for each row
            execute function generate_kode_barang();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists trg_generate_kode_barang on barang');
        DB::unprepared('drop function if exists generate_kode_barang');
        Schema::dropIfExists('barang');
    }
};