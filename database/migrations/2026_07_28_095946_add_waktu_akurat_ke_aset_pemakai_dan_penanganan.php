<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kolom tanggal_penerimaan/tanggal_pengembalian (aset_pemakai) bertipe DATE —
// cuma nyimpen tanggal, jam-menit-detik selalu ke-0 (00:00:00). Akibatnya
// panel "Riwayat Aset" yang nampilin waktu relatif ("X jam lalu") jadi
// ngitung dari tengah malam, bukan dari waktu kejadian sebenarnya --
// makanya kejadian yang baru aja terjadi bisa muncul seolah "9 jam lalu".
//
// Migration ini nambah kolom *_at (datetime, jam-menit-detik lengkap) buat
// nyatet waktu kejadian yang akurat, tanpa ganggu kolom tanggal_* yang lama
// (masih dipakai buat tampilan tanggal & perhitungan durasi_hari).
//
// Catatan: kolom lapor_at/diterima_at/selesai_at buat aset_penanganan yang
// dulunya nempel di migration ini udah dipindah ke migration
// create_aset_penanganan_table (dirapiin, gak numpuk lagi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dateTime('diterima_at')->nullable()->after('tanggal_penerimaan');
            $table->dateTime('dikembalikan_at')->nullable()->after('tanggal_pengembalian');
        });

        // Backfill data lama: gak ada jam aslinya, jadi dikira-kira jam 00:00
        // di tanggal yang udah tercatat -- lebih baik daripada kosong, dan
        // riwayat() di controller tetap fallback ke kolom tanggal_* kalau
        // kolom *_at ini null.
        DB::table('aset_pemakai')->whereNotNull('tanggal_penerimaan')->update([
            'diterima_at' => DB::raw('tanggal_penerimaan'),
        ]);
        DB::table('aset_pemakai')->whereNotNull('tanggal_pengembalian')->update([
            'dikembalikan_at' => DB::raw('tanggal_pengembalian'),
        ]);
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropColumn(['diterima_at', 'dikembalikan_at']);
        });
    }
};