<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');                 // "Laptop", "Proyektor", "Charger", "Tas"
            $table->string('kode', 10)->nullable();  // dipakai di generate kode_inventory, mis. "LAPTOP", "CHRG"
            $table->foreignId('kategori_id')->constrained('kategori')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_kategori');
    }
};
