<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_penanganan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset');

            // nullable: laporan bisa muncul pas aset lagi nganggur (audit gudang),
            // gak selalu ada peminjaman aktif yang nempel.
            $table->foreignId('aset_peminjaman_id')->nullable()->constrained('aset_peminjaman')->nullOnDelete();

            $table->enum('jenis_kerusakan', ['software', 'hardware']);
            $table->text('keluhan');

            $table->date('tanggal_lapor');
            $table->date('tanggal_selesai')->nullable();

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
        Schema::dropIfExists('aset_penanganan');
    }
};