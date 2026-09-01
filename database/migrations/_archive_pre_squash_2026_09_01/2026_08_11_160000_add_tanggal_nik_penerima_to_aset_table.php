<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Kolom ini dibutuhkan oleh import "Bukti Serah Terima/Peminjaman
     * Barang" (AsetBuktiImport) yang sumber Excel-nya punya kolom
     * "Tanggal", "NIK", dan "Penerima" -- sebelumnya kolom ini belum ada
     * di tabel `aset` sehingga import selalu gagal.
     */
    public function up(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->date('tanggal')->nullable()->after('no_bukti');
            $table->string('nik')->nullable()->after('tanggal');
            $table->string('penerima')->nullable()->after('nik');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropColumn(['tanggal', 'nik', 'penerima']);
        });
    }
};