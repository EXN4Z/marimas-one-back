<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_kelengkapan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->foreignId('kelengkapan_master_id')->constrained('kelengkapan_master')->cascadeOnDelete();
            $table->string('keterangan')->nullable(); // contoh: "S/N: 0A3JUGLG5L"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_kelengkapan');
    }
};