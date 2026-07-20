<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenis_aset', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->timestamps();
        });

        DB::table('jenis_aset')->insert([
            ['nama' => 'Laptop', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Mouse', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Monitor', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Printer', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_aset');
    }
};