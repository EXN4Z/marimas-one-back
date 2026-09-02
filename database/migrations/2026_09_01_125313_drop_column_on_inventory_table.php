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
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn('tanggal');
            $table->dropColumn('nik');
            $table->dropColumn('penerima');
            $table->dropColumn('diterima_oleh');
            $table->dropColumn('dibuat_oleh');
            $table->dropColumn('diketahui_hrd');
            $table->dropColumn('departemen_id');
            $table->dropColumn('diketahui');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            //
        });
    }
};
