<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX: migrasi 2026_08_21_132929_update_all_aset_table.php nge-rename
 * `aset_id` -> `inventory_id` di 3 tabel (inventory_pemakai,
 * inventory_penanganan, inventory_writeoff), tapi kelewat satu kolom:
 * `aset_pemakai_id` di tabel `inventory_penanganan` gak ikut di-rename jadi
 * `inventory_pemakai_id`. Padahal Model InventoryPenanganan (fillable,
 * relasi pemakai()) & seluruh controller udah lama nunjuk ke nama kolom
 * `inventory_pemakai_id` -- di production kolomnya masih `aset_pemakai_id`,
 * gak ketahuan sampai ada query yang literally nyebut nama kolom itu di SQL
 * (whereHas), karena eager-load relasi biasa gak error, cuma diam-diam
 * selalu balikin null.
 *
 * FK constraint yang udah nempel ke kolom ini (nunjuk ke tabel
 * `inventory_pemakai`, hasil rename `aset_pemakai` sebelumnya) otomatis
 * ikut kebawa pas kolom di-rename -- gak perlu drop/re-create foreign().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_penanganan', function (Blueprint $table) {
            $table->renameColumn('aset_pemakai_id', 'inventory_pemakai_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_penanganan', function (Blueprint $table) {
            $table->renameColumn('inventory_pemakai_id', 'aset_pemakai_id');
        });
    }
};