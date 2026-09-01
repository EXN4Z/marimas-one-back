<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CATATAN PENTING (bug fix): migrasi lama bikin FK `divisi_id` -> tabel
// `divisi`, tapi tabel `divisi` gak pernah punya migration create-nya
// sendiri. Di database lama itu keburu dipatch manual (bukan lewat
// migration) jadi kolomnya berubah ke `departemen_id` -> tabel `departemen`,
// sesuai yang dipakai app/Models/Pekerja.php sekarang. Migrasi hasil rapian
// ini langsung pakai `departemen_id` dari awal, gak lagi lewat `divisi_id`
// yang bakal gagal kalau di-migrate ke database baru yang bersih.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pekerja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('nik')->nullable()->unique();
            $table->foreignId('departemen_id')->nullable()->constrained('departemen')->nullOnDelete();
            $table->foreignId('jabatan_id')->nullable()->constrained('jabatan')->nullOnDelete();
            $table->foreignId('lokasi_kantor_id')->nullable()->constrained('lokasi_kantor')->nullOnDelete();
            $table->string('qr_code')->nullable()->unique();
            $table->date('tanggal_masuk')->nullable();
            $table->unsignedSmallInteger('kuota_izin_tahunan')->default(12);
            $table->string('foto')->nullable();
            $table->text('face_descriptor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pekerja');
    }
};
