<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Skema final tabel aset_penanganan -- udah dirapiin, gabungan dari beberapa
// migration tambal-sulam yang sebelumnya numpuk di sini:
//   - 2026_07_22_090000 (kolom aset_pemakai_id)
//   - 2026_07_22_151145 & 2026_07_23_080100 (harga_jasa nullable, duplikat)
//   - 2026_07_23_113516 (no-op, dead file, sekarang dihapus)
//   - 2026_07_23_120000 (kolom tanggal_diterima)
//   - 2026_07_24_101839 (biaya_komponen nullable)
//   - 2026_07_28_095946 (kolom lapor_at/diterima_at/selesai_at, bagian penanganan-nya)
//   - 2026_07_30_082601 (kolom foto)
// Migration-migration itu udah dihapus dari repo -- kalau database KAMU udah
// sempat migrate pakai versi lama (kolom-kolomnya nambah satu-satu), gak
// masalah, hasil akhirnya tetap sama persis kayak di bawah ini. Cuma database
// yang migrate:fresh dari nol yang bakal langsung dapet skema ini sekali jalan.
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
            // jadi "sedang diperbaiki"). Frontend (TabAset.tsx) pakai ini buat
            // bedain 3 status: menunggu (null) -> diterima (terisi, tanggal_selesai
            // masih null) -> selesai.
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