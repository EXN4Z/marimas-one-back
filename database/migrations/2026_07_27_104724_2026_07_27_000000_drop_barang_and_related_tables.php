<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur Barang, Stok Menipis, Peminjaman, dan Kategori Barang sudah
     * dihapus dari kode aplikasi. Migration ini menghapus tabel-tabel
     * fisiknya (beserta datanya) yang masih tersisa di database.
     *
     * Urutan drop penting:
     * 1. Lepas FK barang_id di tabel aset (kalau masih ada)
     * 2. mutasi_barang & peminjaman (FK ke barang)
     * 3. barang & kategori_barang
     */
    public function up(): void
    {
        if (Schema::hasColumn('aset', 'barang_id')) {
            Schema::table('aset', function (Blueprint $table) {
                $table->dropForeign(['barang_id']);
                $table->dropColumn('barang_id');
            });
        }

        Schema::dropIfExists('mutasi_barang');
        Schema::dropIfExists('peminjaman');
        Schema::dropIfExists('barang');
        Schema::dropIfExists('kategori_barang');
    }

    public function down(): void
    {
        // Sengaja tidak ada rollback — data lama sudah dianggap tidak
        // relevan lagi setelah fitur ini dihapus permanen.
    }
};