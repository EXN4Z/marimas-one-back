<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 2026_08_27_140000_expand_jenis_kerusakan_enum_on_inventory_penanganan_table
 * sempat dijalankan pas isinya masih versi lama (cuma logic MySQL, belum ada
 * cabang PostgreSQL). Laravel udah nyatet nama file itu "sudah migrate" di
 * tabel `migrations`, jadi walaupun isinya udah diupdate belakangan buat
 * nambahin logic pgsql, `php artisan migrate` bakal skip file itu lagi
 * ("Nothing to migrate") -- Laravel ngecek berdasarkan NAMA FILE yang udah
 * tercatat, bukan isi filenya.
 *
 * Makanya bagian fix khusus PostgreSQL dipindah ke file baru ini (nama
 * beda), biar ke-pick up sebagai migration baru dan constraint check-nya
 * beneran kebentuk.
 *
 * Constraint lama di Postgres masih pakai nama `aset_penanganan_jenis_kerusakan_check`
 * (sisa sebelum tabel di-rename dari `aset_penanganan` ke
 * `inventory_penanganan` -- constraint gak ikut ke-rename otomatis pas
 * Schema::rename() dulu).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS aset_penanganan_jenis_kerusakan_check');
        DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS inventory_penanganan_jenis_kerusakan_check');
        DB::statement("ALTER TABLE inventory_penanganan ADD CONSTRAINT inventory_penanganan_jenis_kerusakan_check CHECK (jenis_kerusakan IN ('software','hardware','tidak_berfungsi','hancur','terputus_sobek'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS inventory_penanganan_jenis_kerusakan_check');
        DB::statement("ALTER TABLE inventory_penanganan ADD CONSTRAINT inventory_penanganan_jenis_kerusakan_check CHECK (jenis_kerusakan IN ('software','hardware'))");
    }
};