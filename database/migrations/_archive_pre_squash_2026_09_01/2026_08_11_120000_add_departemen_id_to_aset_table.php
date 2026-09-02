<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            // nullable + nullOnDelete: aset lama yang belum diassign
            // departemen tetap valid, dan kalau departemennya dihapus,
            // aset TIDAK ikut kehapus -- cuma departemen_id-nya jadi null.
            $table->foreignId('departemen_id')->nullable()
                ->after('jenis_id')
                ->constrained('departemen')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departemen_id');
        });
    }
};