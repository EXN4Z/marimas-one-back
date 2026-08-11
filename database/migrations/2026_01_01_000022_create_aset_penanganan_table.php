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
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->foreignId('aset_pemakai_id')->nullable()->constrained('aset_pemakai')->nullOnDelete();

            $table->enum('jenis_kerusakan', ['software', 'hardware']);
            $table->text('keluhan');
            // foto bukti kerusakan, diupload pas lapor -- nullable karena
            // laporan lama sebelum fitur ini belum punya foto
            $table->string('foto')->nullable();

            $table->date('tanggal_lapor');
            $table->dateTime('lapor_at')->nullable();
            // tanggal_diterima nandain kapan admin nge-accept laporan (status
            // jadi "sedang diperbaiki"). menunggu (null) -> diterima (terisi,
            // tanggal_selesai masih null) -> selesai.
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
        Schema::dropIfExists('aset_penanganan');
    }
};
