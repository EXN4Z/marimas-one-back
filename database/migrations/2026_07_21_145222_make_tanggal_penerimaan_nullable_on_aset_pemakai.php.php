<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            // Request pinjam (status pending) belum punya tanggal_penerimaan,
            // karena baru keisi pas admin approve lewat setujui().
            $table->date('tanggal_penerimaan')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->date('tanggal_penerimaan')->nullable(false)->change();
        });
    }
};