<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED (ke-2). jenis_kerusakan langsung pakai 5 nilai final
 * (riwayat lama cuma 2 nilai lalu di-expand belakangan lewat migration
 * terpisah khusus PostgreSQL).
 *
 * Kolom `dilaporkan_oleh_user_id` dulu ditambah belakangan lewat
 * migration terpisah (add_dilaporkan_oleh_to_inventory_penanganan) --
 * FIX karena siapa yang lapor kerusakan tadinya ditebak dari relasi
 * inventory_pemakai_id (pemakai aktif barang itu), yang keliru kalau
 * laporan dikirim pas item lagi nganggur/gak ada pemakai aktif (audit
 * gudang, atau admin/HR/manajer lapor langsung). Sekarang langsung jadi
 * bagian dari create table-nya sejak awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_penanganan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->nullable()->constrained('inventory')->nullOnDelete();
            $table->foreignId('inventory_pemakai_id')->nullable()->constrained('inventory_pemakai')->nullOnDelete();
            $table->foreignId('dilaporkan_oleh_user_id')->nullable()->constrained('users')->nullOnDelete();

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
