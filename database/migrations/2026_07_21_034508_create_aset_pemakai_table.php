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
            $table->foreignId('pekerja_id')->constrained('pekerja')->cascadeOnDelete();
            $table->string('nomor_penerimaan')->nullable();
            $table->date('tanggal_penerimaan');
            $table->text('catatan_penerimaan')->nullable();
            $table->string('nomor_pengembalian')->nullable();
            $table->date('tanggal_pengembalian')->nullable();
            $table->text('catatan_pengembalian')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_pemakai');
    }
};