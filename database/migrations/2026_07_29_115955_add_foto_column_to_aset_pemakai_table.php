<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nambah kolom foto_penerimaan & foto_pengembalian di tabel aset_pemakai.
     * Disimpan sebagai JSON array of path (bukan foto_1/foto_2/foto_3
     * terpisah), maksimal 3 item per kolom -- batas jumlahnya divalidasi
     * di controller (AsetPemakaiController::store & ::kembalikan), bukan
     * di level database.
     */
    public function up(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->json('foto_penerimaan')->nullable()->after('catatan_penerimaan');
            $table->json('foto_pengembalian')->nullable()->after('catatan_pengembalian');
        });
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropColumn(['foto_penerimaan', 'foto_pengembalian']);
        });
    }
};