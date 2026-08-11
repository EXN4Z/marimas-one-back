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
        Schema::table('aset', function (Blueprint $table) {
            $table->string('no_bukti')->nullable()->after('serial_number');
            $table->string('diterima_oleh')->nullable()->after('keterangan');
            $table->string('diketahui')->nullable()->after('diterima_oleh');
            $table->string('dibuat_oleh')->nullable()->after('diketahui');
            $table->string('diketahui_hrd')->nullable()->after('dibuat_oleh');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropColumn(['no_bukti', 'diterima_oleh', 'diketahui', 'dibuat_oleh', 'diketahui_hrd']);
        });
    }
};
