<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            // Barang dengan is_aset = true dilacak per unit (serial number) lewat
            // tabel `aset`, bukan cuma angka stok di mutasi_barang.
            $table->boolean('is_aset')->default(false)->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('barang', function (Blueprint $table) {
            $table->dropColumn('is_aset');
        });
    }
};
