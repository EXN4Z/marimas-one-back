<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pekerja', function (Blueprint $table) {
            $table->foreignId('lokasi_kantor_id')
                ->nullable()
                ->after('departemen_id')
                ->constrained('lokasi_kantor')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pekerja', function (Blueprint $table) {
            $table->dropForeign(['lokasi_kantor_id']);
            $table->dropColumn('lokasi_kantor_id');
        });
    }
};