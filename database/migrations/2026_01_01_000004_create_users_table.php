<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED (ke-2). Riwayat aslinya lewat 3 tahap:
 *   1. Bikin `users` dengan kolom `role` native Postgres enum
 *      (user_role: guest/karyawan/manajer/hr/admin/cabang) -- gantian
 *      dari ~10 migration lebih tua yang tadinya bikin tabel `pekerja`
 *      terpisah, lalu belakangan diserap ke `users` (lihat SQUASH
 *      pertama di migration ini sebelumnya).
 *   2. `role` di-convert dari enum ke varchar biasa + tabel `roles`
 *      diisi 6 role default (Postgres nolak enum dibanding varchar
 *      tanpa cast eksplisit, bikin GET /api/role selalu 500).
 *   3. `role` (varchar) di-convert ke `role_id` (FK asli ke roles.id),
 *      backfill by name, lalu kolom `role` lama dibuang.
 *
 * Final state aplikasi sekarang: `users` langsung punya `role_id` FK
 * ke `roles` (lihat migration create_roles_table, sekarang dipindah ke
 * SEBELUM file ini) sejak awal dibuat -- gak pernah ada kolom `role`
 * atau tipe enum `user_role` sama sekali. App\Models\User sudah punya
 * accessor/mutator `role` (baca/tulis lewat relasi roleRef ke
 * role_id) jadi semua kode yang baca/tulis $user->role tetap jalan
 * tanpa perubahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->string('phone')->nullable()->unique();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->restrictOnDelete();

            // lokasi_kantor_id: cuma relevan buat akun role 'cabang',
            // nunjuk lokasi kantor mana yang dia urus.
            $table->foreignId('lokasi_kantor_id')
                ->nullable()
                ->constrained('lokasi_kantor')
                ->nullOnDelete();

            // Data karyawan langsung di sini, tidak ada lagi
            // tabel/model Pekerja terpisah.
            $table->string('nik')->nullable()->unique();
            $table->foreignId('departemen_id')->nullable()
                ->constrained('departemen')
                ->nullOnDelete();
            $table->date('tanggal_masuk')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
