<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    Schema::create('kategori', function (Blueprint $table) {
        $table->id();
        $table->string('nama');           // "Barang Utama", "Kelengkapan"
        $table->string('kode')->unique(); // 'barang_utama', 'kelengkapan' -- dipakai di kode program, JANGAN diubah user
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kategori');
    }
};
