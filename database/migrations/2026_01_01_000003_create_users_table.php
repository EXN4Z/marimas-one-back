<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED (hasil rapian dari ~10 migration lama yang gantian
 * bikin tabel `pekerja` terpisah, lalu belakangan nyerap semua kolomnya
 * ke `users` dan drop total tabel `pekerja`). Final state aplikasi
 * sekarang: TIDAK ADA tabel/model Pekerja sama sekali -- data karyawan
 * (nik, departemen_id, tanggal_masuk) langsung nempel di `users`.
 *
 * Migration ini langsung bikin `users` dalam bentuk akhirnya, tidak lagi
 * lewat jalur pekerja -> users seperti riwayat aslinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Native Postgres enum type buat kolom role.
        DB::unprepared(
            "create type user_role as enum ('guest', 'karyawan', 'manajer', 'hr', 'admin', 'cabang')"
        );

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('phone')->nullable()->unique();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->string('reference_photo_path')->nullable();

            $table->timestamps();
        });

        DB::statement("alter table users add column role user_role not null default 'karyawan'");

        Schema::table('users', function (Blueprint $table) {
            // lokasi_kantor_id: cuma relevan buat akun role 'cabang',
            // nunjuk lokasi kantor mana yang dia urus.
            $table->foreignId('lokasi_kantor_id')
                ->nullable()
                ->after('role')
                ->constrained('lokasi_kantor')
                ->nullOnDelete();

            // BARU (eks-pekerja): data karyawan langsung di sini, tidak
            // ada lagi tabel/model Pekerja terpisah.
            $table->string('nik')->nullable()->unique()->after('lokasi_kantor_id');
            $table->foreignId('departemen_id')->nullable()
                ->after('nik')
                ->constrained('departemen')
                ->nullOnDelete();
            $table->date('tanggal_masuk')->nullable()->after('departemen_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        DB::unprepared('drop type if exists user_role');
    }
};
