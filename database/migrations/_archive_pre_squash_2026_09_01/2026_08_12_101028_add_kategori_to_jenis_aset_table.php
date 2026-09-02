<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin sekarang nentuin sendiri tiap jenis_aset itu "Aset Utama" (mis.
     * Laptop, Proyektor) atau "Kelengkapan" (mis. Tas, Charger) lewat kolom
     * ini -- bukan lagi dari tabel asalnya (kelengkapan_master sudah
     * di-drop, lihat migration drop_kelengkapan_master_and_aset_kelengkapan).
     * Kelengkapan sekarang dilacak sebagai baris `aset` biasa juga (kode
     * unik, S/N, status, riwayat pinjam sendiri), cuma jenis_id-nya
     * nunjuk ke jenis_aset yang kategorinya 'kelengkapan'.
     */
    public function up(): void
    {
        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->enum('kategori', ['aset_utama', 'kelengkapan'])
                ->default('aset_utama')
                ->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->dropColumn('kategori');
        });
    }
};