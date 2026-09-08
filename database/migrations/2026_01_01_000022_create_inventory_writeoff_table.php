<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_writeoff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->nullable()->unique()->constrained('inventory')->nullOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('alasan');
            $table->string('no_berita_acara')->nullable();
            $table->date('tanggal_writeoff');
            $table->text('catatan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_writeoff');
    }
};
