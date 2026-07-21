<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_peminjaman', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset');

            // Dua-duanya nullable/optional: kalau peminjam ada di data karyawan,
            // pekerja_id diisi; kalau bukan (mis. orang dari perusahaan lain),
            // cukup nik_peminjam + nama_peminjam. Cara link-nya masih perlu
            // diputusin belakangan, jadi kolomnya disiapin buat dua-duanya.
            $table->foreignId('pekerja_id')->nullable()->constrained('pekerja')->nullOnDelete();
            $table->string('nik_peminjam')->nullable();
            $table->string('nama_peminjam');

            $table->date('tanggal_pinjam');
            $table->string('kondisi_saat_pinjam')->nullable();
            $table->string('no_struk_pinjam')->nullable();

            $table->enum('status', ['dipinjam', 'selesai'])->default('dipinjam');
            $table->date('tanggal_pengembalian')->nullable();
            $table->string('kondisi_saat_kembali')->nullable();
            $table->string('no_struk_pengembalian')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        // Kelengkapan yang ikut dibawa di peminjaman ini (gak selalu semua
        // kelengkapan yang dimiliki aset ikut terbawa tiap peminjaman).
        Schema::create('aset_peminjaman_kelengkapan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_peminjaman_id')->constrained('aset_peminjaman')->cascadeOnDelete();
            $table->foreignId('aset_kelengkapan_id')->constrained('aset_kelengkapan')->cascadeOnDelete();
            $table->string('kondisi')->nullable(); // kondisi kelengkapan pas dipinjam/balik
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_peminjaman_kelengkapan');
        Schema::dropIfExists('aset_peminjaman');
    }
};
