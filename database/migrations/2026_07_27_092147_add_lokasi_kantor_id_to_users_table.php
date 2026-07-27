<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kolom ini cuma dipakai buat akun dengan role 'cabang', buat nandain
    // lokasi kantor mana yang dia urus. Nullable karena role lain (admin,
    // hr, manajer, karyawan) gak butuh kolom ini sama sekali.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('lokasi_kantor_id')
                ->nullable()
                ->after('role')
                ->constrained('lokasi_kantor')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['lokasi_kantor_id']);
            $table->dropColumn('lokasi_kantor_id');
        });
    }
};