<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_penanganan', function (Blueprint $table) {
            // foto bukti kerusakan, diupload pas lapor — nullable karena
            // laporan lama sebelum fitur ini belum punya foto
            $table->string('foto')->nullable()->after('keluhan');
        });
    }

    public function down(): void
    {
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dropColumn('foto');
        });
    }
};