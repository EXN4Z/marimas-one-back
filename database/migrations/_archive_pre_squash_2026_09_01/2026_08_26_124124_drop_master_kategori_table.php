<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop tabel `master_kategori` -- sudah gak dipakai sejak inventory
 * langsung nempel ke `kategori` lewat `kategori_id` (bukan lagi 2 tingkat
 * inventory -> master_kategori -> kategori). Lihat dokumen migrasi Master
 * Kategori -> Kategori.
 *
 * Pakai dropIfExists supaya aman dijalankan berapa kali pun / di kondisi
 * DB manapun (termasuk kalau tabelnya kebetulan udah kehapus lewat jalur
 * lain sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('master_kategori');
    }

    public function down(): void
    {
        Schema::create('master_kategori', function ($table) {
            $table->id();
            $table->string('nama');
            $table->string('kode', 10)->nullable();
            $table->foreignId('kategori_id')->constrained('kategori')->restrictOnDelete();
            $table->timestamps();
        });
    }
};