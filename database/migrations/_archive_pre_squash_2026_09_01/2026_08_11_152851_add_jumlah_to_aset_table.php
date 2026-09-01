<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            // Default 1 karena tiap baris aset di sini pada dasarnya
            // merepresentasikan 1 unit fisik (serial_number unik per baris).
            // Kolom ini disediakan buat kasus non-serialized/bulk item
            // (misal kabel, adaptor generik) yang datang lebih dari 1 unit
            // dalam satu baris pencatatan.
            $table->unsignedInteger('jumlah')->default(1)->after('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropColumn('jumlah');
        });
    }
};