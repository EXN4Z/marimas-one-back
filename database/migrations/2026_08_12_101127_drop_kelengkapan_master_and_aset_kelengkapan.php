<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kelengkapan (Tas, Charger, dst) sekarang dilacak sebagai baris `aset`
    // biasa (kode unik, S/N, status, riwayat pinjam sendiri lewat
    // aset_pemakai -- persis kayak Laptop/Proyektor), bukan lagi sebagai
    // atribut yang nempel ke 1 aset utama. Jenisnya dibedakan lewat kolom
    // jenis_aset.kategori ('aset_utama' vs 'kelengkapan'), bukan dari tabel
    // terpisah lagi. Dikonfirmasi masih kosong di development sebelum drop,
    // jadi aman tanpa perlu migrasi data (lihat AsetBuktiImport.php yang
    // sudah dirombak buat gak nulis ke tabel ini lagi).
    public function up(): void
    {
        Schema::dropIfExists('aset_kelengkapan');
        Schema::dropIfExists('kelengkapan_master');
    }

    // Kalau perlu rollback, tabel dibuat ulang dengan struktur yang sama
    // seperti migration aslinya (2026_01_01_000019 & 2026_01_01_000024).
    public function down(): void
    {
        Schema::create('kelengkapan_master', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->timestamps();
        });

        Schema::create('aset_kelengkapan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset')->cascadeOnDelete();
            $table->foreignId('kelengkapan_master_id')->constrained('kelengkapan_master')->cascadeOnDelete();
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }
};