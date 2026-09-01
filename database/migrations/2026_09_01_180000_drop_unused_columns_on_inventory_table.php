<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * departemen_id, lokasi_kantor_id, dan tanggal_pembelian di tabel
 * inventory gak pernah dipakai di frontend (gak ada di form, gak
 * ditampilkan di mana pun) -- dihapus total, bukan ditambahkan balik.
 *
 * Idempotent (cek hasColumn dulu) supaya aman dijalankan di environment
 * manapun, apapun sisa riwayat migration lama yang udah kejalan di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            if (Schema::hasColumn('inventory', 'departemen_id')) {
                $table->dropConstrainedForeignId('departemen_id');
            }
            if (Schema::hasColumn('inventory', 'lokasi_kantor_id')) {
                $table->dropConstrainedForeignId('lokasi_kantor_id');
            }
            if (Schema::hasColumn('inventory', 'tanggal_pembelian')) {
                $table->dropColumn('tanggal_pembelian');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory', 'tanggal_pembelian')) {
                $table->date('tanggal_pembelian')->nullable();
            }
            if (!Schema::hasColumn('inventory', 'departemen_id')) {
                $table->foreignId('departemen_id')->nullable()
                    ->constrained('departemen')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('inventory', 'lokasi_kantor_id')) {
                $table->foreignId('lokasi_kantor_id')->nullable()
                    ->constrained('lokasi_kantor')
                    ->nullOnDelete();
            }
        });
    }
};