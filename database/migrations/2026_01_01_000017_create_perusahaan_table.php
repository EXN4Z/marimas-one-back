<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menu "Perusahaan" di Master Data -- mirror struktur dari lokasi_kantor
// (menu "Cabang"), sengaja TANPA relasi ke tabel lain (users, dll).
//
// Diposisikan di sini (sebelum kategori/inventory), bukan di tanggal
// aslinya migration ini ditulis, supaya inventory.perusahaan_id bisa
// langsung di-constrain ke tabel ini dari awal pembuatan tabel inventory
// -- lihat create_inventory_table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perusahaan', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->text('alamat')->nullable();
            $table->string('link', 255)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perusahaan');
    }
};
