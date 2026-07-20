<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            // Tunjangan tetap per bulan (transport, makan, dll digabung jadi satu angka),
            // ditambahkan ke gaji pokok sebelum dikurangi potongan.
            $table->decimal('tunjangan', 14, 2)->default(0)->after('gaji_pokok');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropColumn('tunjangan');
        });
    }
};
