<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menu "Role" di Master Data -- data referensi buat role yang bisa
// diassign ke user (kolom users.role masih plain string, BUKAN foreign
// key ke sini, biar gak perlu migrasi data users.role yang udah ada).
// `nama` HARUS persis sama kayak nilai yang dipakai di kolom users.role
// (karyawan/cabang/manajer/hr/admin) supaya nyambung ke User::roleLevel().
// `level` dipakai App\Models\User::hasRoleAtLeast()/roleLevel() buat
// nentuin hak akses lintas role -- lihat catatan lengkap di User.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->string('label', 100)->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};