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
        Schema::table('kategori', function(Blueprint $table) {
            $table->dropColumn('kode');
        });
        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable()->constrained('kategori')->restrictOnDelete();
            $table->dropColumn('master_kategori_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
