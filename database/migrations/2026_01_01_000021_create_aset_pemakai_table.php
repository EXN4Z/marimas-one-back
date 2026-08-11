<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_pemakai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->foreignId('pekerja_id')->nullable()->constrained('pekerja')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // default 'disetujui' biar data lama & serah-terima admin langsung tetap jalan
            $table->enum('status', ['pending', 'disetujui', 'ditolak'])->default('disetujui');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nomor_penerimaan')->nullable();
            $table->string('no_struk_penerimaan')->nullable();
            $table->date('tanggal_penerimaan')->nullable();
            $table->json('foto_penerimaan')->nullable();
            $table->dateTime('diterima_at')->nullable();
            $table->text('catatan_penerimaan')->nullable();

            $table->string('nomor_pengembalian')->nullable();
            $table->string('no_struk_pengembalian')->nullable();
            $table->date('tanggal_pengembalian')->nullable();
            $table->json('foto_pengembalian')->nullable();
            $table->dateTime('dikembalikan_at')->nullable();
            $table->text('catatan_pengembalian')->nullable();

            $table->text('catatan_penolakan')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_pemakai');
    }
};
