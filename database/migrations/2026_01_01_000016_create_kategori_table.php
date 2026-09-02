<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED. Riwayat aslinya lewat 3 generasi tabel kategori
 * (enum di jenis_aset -> tabel kategori+jenis_aset -> kategori+master_kategori
 * 2 tingkat) sebelum akhirnya disederhanakan jadi 1 tabel flat tanpa kolom
 * `kode` sama sekali -- lihat App\Models\MasterData\Kategori. Kategori
 * sekarang murni label bebas yang dikelola admin, tidak lagi menentukan
 * golongan/tipe barang (itu urusan Inventory::parent_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori');
    }
};
