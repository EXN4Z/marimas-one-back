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
        Schema::table('inventory_pemakai', function(Blueprint $table) {
            $table->renameColumn('aset_id', 'inventory_id');
            $table->foreign('inventory_id')
                  ->references('id')
                  ->on('inventory')
                  ->nullOnDelete();
            $table->dropColumn('aset_kelengkapan_id');
        });
        Schema::table('inventory_penanganan', function(Blueprint $table) {
            $table->renameColumn('aset_id', 'inventory_id');
            $table->foreign('inventory_id')
                  ->references('id')
                  ->on('inventory')
                  ->nullOnDelete();
        });
        Schema::table('inventory_writeoff', function(Blueprint $table) {
            $table->renameColumn('aset_id', 'inventory_id');
            $table->foreign('inventory_id')
                  ->references('id')
                  ->on('inventory')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_pemakai', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->renameColumn('inventory_id', 'aset_id');
            $table->foreign('aset_id')->references('id')->on('aset')->nullOnDelete();
        });
        Schema::table('inventory_penanganan', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->renameColumn('inventory_id', 'aset_id');
            $table->foreign('aset_id')->references('id')->on('aset')->nullOnDelete();
        });
        Schema::table('inventory_writeoff', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->renameColumn('inventory_id', 'aset_id');
            $table->foreign('aset_id')->references('id')->on('aset')->nullOnDelete();
        });
    }
};
