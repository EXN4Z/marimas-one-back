<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Native Postgres enum type buat kolom role. Nilainya udah termasuk
        // 'cabang' (ditambahin belakangan lewat ALTER TYPE ADD VALUE di
        // riwayat migrasi lama, sekarang langsung dimasukin dari awal).
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

        // Kolom role: pake type user_role, ditaruh setelah kolom lain biar
        // urutan tabel gampang dibaca. lokasi_kantor_id nunjuk lokasi kantor
        // yang diurus, cuma kepake buat akun role 'cabang'.
        DB::statement("alter table users add column role user_role not null default 'karyawan'");

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
        Schema::dropIfExists('users');
        DB::unprepared('drop type if exists user_role');
    }
};
