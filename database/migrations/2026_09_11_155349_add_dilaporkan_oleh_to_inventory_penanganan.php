<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX: siapa yang lapor kerusakan selama ini ditebak dari relasi
 * inventory_pemakai_id (pemakai aktif barang itu). Itu keliru kalau
 * laporan dikirim pas item lagi nganggur/gak ada pemakai aktif (audit
 * gudang, atau admin/HR/manajer lapor langsung) -- pemakai jadi null,
 * jadi (a) teks notifikasi jatuh ke fallback generik "Karyawan" padahal
 * yang lapor jelas ada, dan (b) notif "laporan selesai" bahkan gak
 * terkirim sama sekali ke pelapor. Kolom ini nyimpen pelapor asli
 * (request()->user() pas submit), independen dari status pemakaian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_penanganan', function (Blueprint $table) {
            $table->foreignId('dilaporkan_oleh_user_id')
                ->nullable()
                ->after('inventory_pemakai_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_penanganan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dilaporkan_oleh_user_id');
        });
    }
};