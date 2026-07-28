<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur Barang, Stok Menipis, Peminjaman, dan Kategori Barang sudah
     * dihapus dari kode aplikasi. Migration ini menghapus tabel-tabel
     * fisiknya (beserta datanya) yang masih tersisa di database.
     *
     * FIX: sebelumnya drop tabel satu-satu pakai dropIfExists() dan gagal
     * di Postgres — "cannot drop table barang because other objects depend
     * on it" (mis. FK barang_units_barang_id_foreign di tabel barang_units,
     * yang gak ke-drop duluan karena gak ada di daftar semula). Daripada
     * nebak-nebak dan nge-list ulang satu-satu tabel dependen yang mungkin
     * kelewat, pakai DROP ... CASCADE langsung: otomatis ikut nyabut semua
     * FK/objek lain yang masih nempel ke tabel-tabel ini, gak peduli
     * namanya apa.
     */
    public function up(): void
    {
        if (Schema::hasColumn('aset', 'barang_id')) {
            Schema::table('aset', function (Blueprint $table) {
                $table->dropForeign(['barang_id']);
                $table->dropColumn('barang_id');
            });
        }

        DB::statement('DROP TABLE IF EXISTS mutasi_barang, peminjaman, barang_units, barang, kategori_barang CASCADE');
    }

    public function down(): void
    {
        // Sengaja tidak ada rollback — data lama sudah dianggap tidak
        // relevan lagi setelah fitur ini dihapus permanen.
    }
};