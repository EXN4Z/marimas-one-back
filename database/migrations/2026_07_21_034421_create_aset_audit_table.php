<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset');
            $table->foreignId('petugas_id')->constrained('users');
            $table->date('tanggal_audit');
            $table->enum('hasil', ['cocok', 'hilang', 'rusak'])->default('cocok');
            $table->text('catatan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_audit');
    }
};
