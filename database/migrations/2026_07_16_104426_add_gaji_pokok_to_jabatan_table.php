<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->decimal('gaji_pokok', 14, 2)->default(0)->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropColumn('gaji_pokok');
        });
    }
};