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
        Schema::rename('aset_pemakai', 'inventory_pemakai');
        Schema::rename('aset_penanganan', 'inventory_penanganan');
        Schema::rename('aset_writeoff', 'inventory_writeoff');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
