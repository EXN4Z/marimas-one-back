<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Migrasi destruktif: menyerap kolom-kolom dari tabel `pekerja` ke `users`,
// lalu menghapus tabel `pekerja` secara total (bukan rename, bukan
// dipertahankan). Kolom yang TIDAK ikut pindah (di-drop permanen bersama
// tabel pekerja): jabatan_id, qr_code, kuota_izin_tahunan, foto,
// face_descriptor -- lihat instruksi "Hapus Tabel pekerja" poin 0 untuk
// alasannya masing-masing.
//
// WAJIB backup tabel `pekerja` dan `aset_pemakai` sebelum migration ini
// dijalankan di production. Migration ini cuma boleh jalan sekali.
return new class extends Migration
{
    public function up(): void
    {
        // 1) Tambah kolom baru di users. lokasi_kantor_id TIDAK ditambah
        //    di sini karena sudah ada dari migration create_users_table
        //    (dipakai dobel: akun cabang & karyawan biasa, lihat poin 0).
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik')->nullable()->unique()->after('role');
            $table->foreignId('departemen_id')->nullable()
                ->after('lokasi_kantor_id')
                ->constrained('departemen')
                ->nullOnDelete();
            $table->date('tanggal_masuk')->nullable()->after('departemen_id');
        });

        // 2) Migrasikan data lama dari pekerja -> users (one-way, sekali jalan).
        //    departemen_id & tanggal_masuk & nik dari pekerja, lokasi_kantor_id
        //    ikut dipindah juga karena kolom ini dulu ada di pekerja (beda
        //    baris dari lokasi_kantor_id milik akun cabang di users).
        DB::statement(<<<'SQL'
            UPDATE users
            SET
                nik = pekerja.nik,
                departemen_id = pekerja.departemen_id,
                tanggal_masuk = pekerja.tanggal_masuk,
                lokasi_kantor_id = COALESCE(users.lokasi_kantor_id, pekerja.lokasi_kantor_id)
            FROM pekerja
            WHERE pekerja.user_id = users.id
        SQL);

        // 3) Backfill aset_pemakai.user_id dari pekerja_id, karena selama ini
        //    baris pemakai-karyawan disimpan lewat pekerja_id (bukan user_id).
        //    Setelah ini aset_pemakai cuma pakai satu kolom identitas: user_id.
        DB::statement(<<<'SQL'
            UPDATE aset_pemakai
            SET user_id = pekerja.user_id
            FROM pekerja
            WHERE aset_pemakai.pekerja_id = pekerja.id
              AND aset_pemakai.user_id IS NULL
        SQL);

        // 4) Hapus FK + kolom pekerja_id di aset_pemakai SEBELUM drop tabel
        //    pekerja, karena FK-nya nunjuk ke situ.
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropForeign(['pekerja_id']);
            $table->dropColumn('pekerja_id');
        });

        // 5) Drop tabel pekerja secara total. Tidak di-rename, tidak
        //    dipertahankan dalam bentuk apapun.
        Schema::dropIfExists('pekerja');
    }

    public function down(): void
    {
        // Rollback darurat saja -- bukan tanda tabel pekerja "dipertahankan".
        // jabatan_id/qr_code/kuota_izin_tahunan/foto/face_descriptor TIDAK
        // bisa dipulihkan isinya (memang sengaja di-drop permanen), jadi
        // kolom-kolom itu dibuat ulang kosong/default supaya struktur lama
        // tetap valid kalau ada kode lama yang masih rujuk ke sana.
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

        DB::statement(<<<'SQL'
            INSERT INTO pekerja (user_id, nik, departemen_id, lokasi_kantor_id, tanggal_masuk, created_at, updated_at)
            SELECT id, nik, departemen_id, lokasi_kantor_id, tanggal_masuk, now(), now()
            FROM users
            WHERE nik IS NOT NULL OR departemen_id IS NOT NULL OR tanggal_masuk IS NOT NULL
        SQL);

        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->foreignId('pekerja_id')->nullable()->after('aset_id')
                ->constrained('pekerja')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE aset_pemakai
            SET pekerja_id = pekerja.id
            FROM pekerja
            WHERE pekerja.user_id = aset_pemakai.user_id
        SQL);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departemen_id');
            $table->dropColumn(['nik', 'tanggal_masuk']);
        });
    }
};