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
        Schema::table('aset_kelengkapan', function (Blueprint $table) {
            // nullable + nullOnDelete: kelengkapan boleh berdiri sendiri (belum
            // ke-attach ke aset induk manapun), dan kalau aset induknya dihapus,
            // kelengkapan TIDAK ikut kehapus -- cuma aset_id-nya jadi null.
            $table->foreignId('aset_id')->nullable()
                ->after('kode_kelengkapan')
                ->constrained('aset')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aset_kelengkapan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aset_id');
        });
    }
};