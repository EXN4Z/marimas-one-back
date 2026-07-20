<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_perbaikan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->date('tanggal_perbaikan');
            $table->text('keterangan_kerusakan');
            $table->string('teknisi_vendor')->nullable();
            $table->decimal('biaya', 12, 2)->nullable();
            $table->string('status')->default('proses'); // proses, selesai
            $table->date('tanggal_selesai')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_perbaikan');
    }
};