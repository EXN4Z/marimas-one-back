<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `tanggal_rusak` sebelumnya cuma pernah ada di tabel lama
 * `aset_kelengkapan` (lihat migration
 * 2026_08_18_095308_add_tanggal_rusak_on_aset_kelengkapan_table.php).
 * Begitu Kelengkapan digabung jadi baris `inventory` biasa (lihat
 * rename_aset_table & drop_aset_kelengkapan_table), kolom ini gak pernah
 * ikut dibikin di tabel `inventory` yang baru -- padahal
 * InventoryController::laporRusakKelengkapan() (dan sekarang juga alur
 * InventoryPenanganan buat Kelengkapan) tetap nulis ke kolom ini. Migration
 * ini nutup celah itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dateTime('tanggal_rusak')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn('tanggal_rusak');
        });
    }
};