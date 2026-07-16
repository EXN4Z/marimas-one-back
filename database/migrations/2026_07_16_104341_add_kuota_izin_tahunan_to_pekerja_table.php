<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pekerja', function (Blueprint $table) {
            // Jatah izin pribadi (yang paling deket ke konsep "cuti tahunan") per tahun, dalam hari.
            $table->unsignedSmallInteger('kuota_izin_tahunan')->default(12)->after('tanggal_masuk');
        });
    }

    public function down(): void
    {
        Schema::table('pekerja', function (Blueprint $table) {
            $table->dropColumn('kuota_izin_tahunan');
        });
    }
};