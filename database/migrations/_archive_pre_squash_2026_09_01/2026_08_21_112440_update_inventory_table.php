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
        Schema::table('inventory', function(Blueprint $table) {
            $table->dropColumn('kode_aset');
            $table->string('kode_inventory')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('inventory')->nullOnDelete();
            $table->foreignId('master_kategori_id')->nullable()->constrained('master_kategori')->nullOnDelete();
            $table->string('nama')->nullable();
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
