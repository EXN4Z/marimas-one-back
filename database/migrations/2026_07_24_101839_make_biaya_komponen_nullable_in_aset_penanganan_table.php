<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// biaya_komponen kelewat pas harga_jasa dibikin nullable (lihat migration
// 2026_07_23_080100), padahal butuh nullable juga -- hasil "rusak_berat"
// sengaja ngirim biaya_komponen = null, dan itu masih kena NOT NULL
// constraint lama dari database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->decimal('biaya_komponen', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // kalau mau di-rollback, isi dulu null jadi 0 biar nggak gagal set NOT NULL lagi
        DB::statement('UPDATE aset_penanganan SET biaya_komponen = 0 WHERE biaya_komponen IS NULL');
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->decimal('biaya_komponen', 12, 2)->nullable(false)->change();
        });
    }
};