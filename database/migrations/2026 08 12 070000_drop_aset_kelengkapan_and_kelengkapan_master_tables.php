<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * aset_pemakai ternyata punya kolom aset_kelengkapan_id (FK ke
     * aset_kelengkapan) yang gak ada di migration manapun di codebase ini
     * -- kemungkinan ditambah migration lain yang belum ke-sync. FK ini
     * WAJIB didrop dulu sebelum drop aset_kelengkapan, kalau enggak
     * Postgres nolak (dependent objects still exist).
     *
     * Urutan drop tabel: aset_kelengkapan DULU (FK ke kelengkapan_master
     * & aset), baru kelengkapan_master.
     */
    public function up(): void
    {
        if (Schema::hasColumn('aset_pemakai', 'aset_kelengkapan_id')) {
            Schema::table('aset_pemakai', function (Blueprint $table) {
                $table->dropForeign(['aset_kelengkapan_id']);
                $table->dropColumn('aset_kelengkapan_id');
            });
        }

        Schema::dropIfExists('aset_kelengkapan');
        Schema::dropIfExists('kelengkapan_master');
    }

    /**
     * down() sengaja TIDAK bikin ulang tabelnya/kolomnya (biar gak
     * nyimpang jadi 2 sumber kebenaran). Kalau butuh rollback, jalankan
     * ulang migration create yang lama.
     */
    public function down(): void
    {
        // Intentionally left blank -- lihat catatan di atas.
    }
};