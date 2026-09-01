<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migrasi destruktif: hapus tabel `jabatan` secara total. Halaman "Jabatan"
// di Master Data dan controller/model/route terkait sudah dihapus dari
// kode. Tabel `jabatan` sudah gak punya relasi aktif dari mana pun --
// tabel `pekerja` (satu-satunya yang dulu punya FK jabatan_id) sudah
// didrop duluan di migration 2026_08_13_145807, dan tabel `users` gak
// pernah menyerap kolom jabatan_id itu. Jadi drop ini aman tanpa perlu
// urus FK tabel lain.
//
// WAJIB backup tabel `jabatan` sebelum migration ini dijalankan di
// production kalau datanya masih mau disimpan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('jabatan');
    }

    public function down(): void
    {
        // Rollback darurat saja -- data lama (nama/gaji_pokok/tunjangan)
        // TIDAK bisa dipulihkan, cuma struktur tabelnya dibuat ulang kosong
        // supaya kode lama yang mungkin masih rujuk ke sana tetap valid.
        Schema::create('jabatan', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->decimal('gaji_pokok', 14, 2)->default(0);
            $table->decimal('tunjangan', 14, 2)->default(0);
            $table->timestamps();
        });
    }
};