<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelengkapan_master', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->timestamps();
        });

        DB::table('kelengkapan_master')->insert([
            ['nama' => 'Charger', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Tas', 'created_at' => now(), 'updated_at' => now()],
            ['nama' => 'Mouse', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kelengkapan_master');
    }
};