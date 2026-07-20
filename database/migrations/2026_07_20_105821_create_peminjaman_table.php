<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sesuaikan nama tabel 'barangs' kalau di project kamu namanya beda
        Schema::create('peminjaman', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained('barang')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('jumlah');
            $table->timestamp('tanggal_pinjam')->useCurrent();
            $table->date('tanggal_kembali_rencana');
            $table->timestamp('tanggal_kembali_aktual')->nullable();
            $table->enum('status', ['dipinjam', 'dikembalikan'])->default('dipinjam');
            $table->timestamps();

            $table->index(['barang_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peminjaman');
    }
};