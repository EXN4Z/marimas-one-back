<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nandain kapan admin nge-accept laporan (status jadi "sedang diperbaiki").
        // Frontend (TabAset.tsx) udah pakai field ini buat bedain 3 status:
        // menunggu (null) -> diterima (terisi, tanggal_selesai masih null) -> selesai.
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->date('tanggal_diterima')->nullable()->after('tanggal_lapor');
        });
    }

    public function down(): void
    {
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dropColumn('tanggal_diterima');
        });
    }
};