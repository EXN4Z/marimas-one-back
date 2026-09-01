<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kelengkapan aset gak wajib nempel ke aset induk (aset_id sudah
     * nullable sejak migrasi make_aset_id_nullable_on_aset_kelengkapan_table).
     * Kolom ini buat nyimpen LOKASI kelengkapan itu kalau dia berdiri
     * sendiri (mis. printer cadangan yang taruh di cabang Bandung, tapi
     * belum/tidak nempel ke aset utama tertentu).
     */
    public function up(): void
    {
        Schema::table('aset_kelengkapan', function (Blueprint $table) {
            $table->foreignId('lokasi_kantor_id')
                ->nullable()
                ->after('aset_id')
                ->constrained('lokasi_kantor')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aset_kelengkapan', function (Blueprint $table) {
            $table->dropForeign(['lokasi_kantor_id']);
            $table->dropColumn('lokasi_kantor_id');
        });
    }
};