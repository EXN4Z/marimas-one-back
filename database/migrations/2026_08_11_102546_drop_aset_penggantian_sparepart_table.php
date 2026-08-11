<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Fitur "penggantian sparepart" dihapus -- fungsinya sudah tercakup di
    // aset_penanganan (riwayat perbaikan), yang punya field lebih lengkap
    // (harga_jasa, biaya_komponen, hasil, no_struk, catatan). Tabel ini
    // dikonfirmasi masih kosong di production sebelum dihapus, jadi aman
    // drop langsung tanpa perlu migrasi data.
    public function up(): void
    {
        Schema::dropIfExists('aset_penggantian_sparepart');
    }

    // Kalau perlu rollback, tabel dibuat ulang dengan struktur yang sama
    // seperti migration aslinya (2026_01_01_000025).
    public function down(): void
    {
        Schema::create('aset_penggantian_sparepart', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('nama_sparepart');
            $table->text('keterangan')->nullable();
            $table->decimal('biaya', 12, 2)->nullable();
            $table->timestamps();
        });
    }
};