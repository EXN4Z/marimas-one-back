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
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->foreignId('aset_kelengkapan_id')->nullable()
                ->constrained('aset_kelengkapan')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropForeign(['aset_kelengkapan_id']);
            $table->dropColumn('aset_kelengkapan_id');
        });
    }
};
