<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE aset_penanganan ALTER COLUMN harga_jasa DROP NOT NULL');
    }

    public function down(): void
    {
        // kalau mau di-rollback, isi dulu null jadi 0 biar nggak gagal set NOT NULL lagi
        DB::statement('UPDATE aset_penanganan SET harga_jasa = 0 WHERE harga_jasa IS NULL');
        DB::statement('ALTER TABLE aset_penanganan ALTER COLUMN harga_jasa SET NOT NULL');
    }
};