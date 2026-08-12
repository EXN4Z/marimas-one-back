<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kategori jenis_aset dipindah dari kolom enum ke tabel tersendiri biar
     * admin bisa nambah kategori baru lewat Master Data tanpa migration baru
     * (sebelumnya cuma bisa 'aset_utama' / 'kelengkapan' yang di-hardcode di
     * enum, lihat migration add_kategori_to_jenis_aset_table).
     *
     * Kolom `kode` tetap dipertahankan persis sama nilainya ('aset_utama',
     * 'kelengkapan') supaya semua kode lama (filter ?kategori=, whereHas,
     * import Excel, frontend) yang masih ngerujuk ke nilai itu tidak perlu
     * diubah -- lihat migration berikutnya buat detail migrasi datanya.
     */
    public function up(): void
    {
        Schema::create('kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('kode')->unique();
            $table->timestamps();
        });

        DB::table('kategori')->insert([
            ['nama' => 'Barang', 'kode' => 'aset_utama', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Kelengkapan', 'kode' => 'kelengkapan', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori');
    }
};