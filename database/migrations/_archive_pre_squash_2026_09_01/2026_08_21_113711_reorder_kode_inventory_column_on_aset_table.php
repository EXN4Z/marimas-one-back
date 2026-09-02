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
            $table->string('kode_inventory')->change()->after('id');
            $table->foreignId('parent_id')->nullable()->change()->after('kode_inventory');
            $table->foreignId('master_kategori_id')->nullable()->change()->after('parent_id');
            $table->string('nama')->nullable()->change()->after('master_kategori_id');
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
