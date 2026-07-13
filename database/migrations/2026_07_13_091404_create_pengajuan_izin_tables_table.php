<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel baru & terpisah dari pengajuan_cuti (yang lama dibiarkan apa
     * adanya / dipensiunkan). Menampung seluruh jenis izin sesuai spec:
     * pribadi, sakit, terlambat, pulang cepat, dinas, lainnya.
     */
    public function up(): void
    {
        Schema::create('pengajuan_izin', function (Blueprint $table) {
            $table->id();

            // Nomor izin unik yang ditampilkan ke user, contoh: IZN-240001
            $table->string('nomor_izin')->unique();

            $table->foreignId('karyawan_id')->constrained('users')->onDelete('cascade');

            $table->enum('jenis_izin', [
                'pribadi',
                'sakit',
                'terlambat',
                'pulang_cepat',
                'dinas',
                'lainnya',
            ]);

            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('alasan');

            // Path file di storage (surat dokter, surat undangan, dsb)
            $table->string('bukti_path')->nullable();
            $table->string('kontak_darurat')->nullable();

            $table->enum('status', [
                'draft',
                'pending',
                'disetujui',
                'ditolak',
                'revisi',
                'selesai',
            ])->default('pending');

            $table->text('catatan_atasan')->nullable();

            // Siapa yang approve/reject, dan kapan
            $table->foreignId('direview_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('direview_at')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['jenis_izin']);
            $table->index(['tanggal_mulai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_izin');
    }
};