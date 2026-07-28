<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kolom tanggal_penerimaan/tanggal_pengembalian (aset_pemakai) dan
// tanggal_lapor/tanggal_diterima/tanggal_selesai (aset_penanganan) semuanya
// bertipe DATE — cuma nyimpen tanggal, jam-menit-detik selalu ke-0 (00:00:00).
// Akibatnya panel "Riwayat Aset" yang nampilin waktu relatif ("X jam lalu")
// jadi ngitung dari tengah malam, bukan dari waktu kejadian sebenarnya —
// makanya kejadian yang baru aja terjadi bisa muncul seolah "9 jam lalu".
//
// Migration ini nambah kolom *_at (datetime, jam-menit-detik lengkap) buat
// nyatet waktu kejadian yang akurat, tanpa ganggu kolom tanggal_* yang lama
// (masih dipakai buat tampilan tanggal & perhitungan durasi_hari).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dateTime('diterima_at')->nullable()->after('tanggal_penerimaan');
            $table->dateTime('dikembalikan_at')->nullable()->after('tanggal_pengembalian');
        });

        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dateTime('lapor_at')->nullable()->after('tanggal_lapor');
            $table->dateTime('diterima_at')->nullable()->after('tanggal_diterima');
            $table->dateTime('selesai_at')->nullable()->after('tanggal_selesai');
        });

        // Backfill data lama: gak ada jam aslinya, jadi dikira-kira jam 00:00
        // di tanggal yang udah tercatat — lebih baik daripada kosong, dan
        // riwayat() di controller tetap fallback ke kolom tanggal_* kalau
        // kolom *_at ini null.
        DB::table('aset_pemakai')->whereNotNull('tanggal_penerimaan')->update([
            'diterima_at' => DB::raw('tanggal_penerimaan'),
        ]);
        DB::table('aset_pemakai')->whereNotNull('tanggal_pengembalian')->update([
            'dikembalikan_at' => DB::raw('tanggal_pengembalian'),
        ]);
        DB::table('aset_penanganan')->whereNotNull('tanggal_lapor')->update([
            'lapor_at' => DB::raw('tanggal_lapor'),
        ]);
        DB::table('aset_penanganan')->whereNotNull('tanggal_diterima')->update([
            'diterima_at' => DB::raw('tanggal_diterima'),
        ]);
        DB::table('aset_penanganan')->whereNotNull('tanggal_selesai')->update([
            'selesai_at' => DB::raw('tanggal_selesai'),
        ]);
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropColumn(['diterima_at', 'dikembalikan_at']);
        });

        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dropColumn(['lapor_at', 'diterima_at', 'selesai_at']);
        });
    }
};