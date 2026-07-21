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
            // Nullable: kerusakan bisa ketemu pas lagi dipinjam ATAU pas lagi
            // nganggur di gudang (ketemu waktu audit), jadi gak selalu ada
            // peminjaman yang aktif.
            $table->foreignId('aset_peminjaman_id')->nullable()->constrained('aset_peminjaman')->nullOnDelete();

            $table->enum('jenis_kerusakan', ['software', 'hardware']);
            $table->text('keluhan');
            $table->date('tanggal_lapor');
            $table->date('tanggal_selesai')->nullable(); // durasi = tanggal_selesai - tanggal_lapor, gak disimpan dobel
            $table->decimal('harga_jasa', 14, 2)->default(0);
            $table->decimal('biaya_komponen', 14, 2)->default(0);

            // null = masih ditangani, 'diperbaiki' = balik dipinjam,
            // 'rusak_berat' = lanjut ke write-off.
            $table->enum('hasil', ['diperbaiki', 'rusak_berat'])->nullable();
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
