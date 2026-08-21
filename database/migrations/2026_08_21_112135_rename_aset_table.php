<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('aset', 'inventory');
    }

    public function down(): void
    {
        Schema::rename('inventory', 'aset');
    }
};