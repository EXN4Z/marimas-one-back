<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nambah kolom tanggal_garansi (DATE, nullable) ke tabel aset. Diisi admin
// pas tambah/edit aset lewat AsetController (route-nya udah dibatasi
// role:admin, jadi gak perlu otorisasi tambahan khusus kolom ini).
// Ditaruh setelah serial_number biar urutan kolom di DB selaras sama
// urutan field di form: warna, serial_number, tanggal_garansi, perusahaan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->date('tanggal_garansi')->nullable()->after('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropColumn('tanggal_garansi');
        });
    }
};