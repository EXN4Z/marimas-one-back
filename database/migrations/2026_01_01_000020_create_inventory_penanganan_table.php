<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED. jenis_kerusakan langsung pakai 5 nilai final (riwayat
 * lama cuma 2 nilai lalu di-expand belakangan lewat migration terpisah
 * khusus PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_penanganan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->nullable()->constrained('inventory')->nullOnDelete();
            $table->foreignId('inventory_pemakai_id')->nullable()->constrained('inventory_pemakai')->nullOnDelete();

            $table->enum('jenis_kerusakan', ['software', 'hardware', 'tidak_berfungsi', 'hancur', 'terputus_sobek']);
            $table->text('keluhan');
            $table->string('foto')->nullable();

            $table->date('tanggal_lapor');
            $table->dateTime('lapor_at')->nullable();
            $table->date('tanggal_diterima')->nullable();
            $table->dateTime('diterima_at')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->dateTime('selesai_at')->nullable();

            $table->decimal('harga_jasa', 12, 2)->nullable();
            $table->decimal('biaya_komponen', 12, 2)->nullable();
            $table->string('hasil')->nullable();
            $table->string('no_struk')->nullable();
            $table->text('catatan')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_penanganan');
    }
};
