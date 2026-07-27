<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur Barang, Stok Menipis, Peminjaman, dan Kategori Barang sudah
     * dihapus dari kode aplikasi. Migration ini menghapus tabel-tabel
     * fisiknya (beserta datanya) yang masih tersisa di database.
     *
     * Urutan drop penting: mutasi_barang & peminjaman punya foreign key
     * ke barang, jadi harus di-drop dulu sebelum barang & kategori_barang.
     */
    public function up(): void
    {
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