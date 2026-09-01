<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penutup migrasi Master Kategori -> Kategori. Dijalankan setelah
 * inventory.kategori_id di-backfill (migration
 * 2026_08_26_105251_delete_kode_column_on_kategori_table) dan trigger
 * generate_kode_inventory dipindah sumbernya ke `nama` (migration
 * 2026_08_26_111347_update_generate_kode_inventory_use_nama), jadi
 * `master_kategori` sudah tidak dipakai kolom/trigger manapun lagi.
 *
 * - inventory.kategori_id di-set NOT NULL (backfill sudah kelar di
 *   migration sebelumnya, sekarang wajib diisi -- selaras sama validasi
 *   `required` di InventoryController & payload yang selalu dikirim FE).
 *
 * Drop tabel master_kategori DIPISAH ke migration tersendiri
 * (2026_08_26_130000_drop_master_kategori_table.php) biar tanggung
 * jawabnya jelas & bisa dijalankan independen dari perubahan kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable()->change();
        });
    }
};