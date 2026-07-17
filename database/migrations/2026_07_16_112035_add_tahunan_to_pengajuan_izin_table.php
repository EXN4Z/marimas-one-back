<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan 'tahunan' (Cuti Tahunan) ke daftar jenis_izin, sebagai
     * bagian dari penggabungan fitur Pengajuan Cuti ke dalam Pengajuan Izin.
     * Tabel & fitur pengajuan_cuti yang lama tetap dibiarkan ada (dipakai
     * untuk data historis di Dashboard Analytics), tidak dihapus.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pengajuan_izin ALTER COLUMN jenis_izin TYPE VARCHAR(255) USING jenis_izin::text');
            DB::statement('ALTER TABLE pengajuan_izin DROP CONSTRAINT IF EXISTS pengajuan_izin_jenis_izin_check');
            return;
        }

        Schema::table('pengajuan_izin', function (Blueprint $table) {
            $table->string('jenis_izin')->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pengajuan_izin ALTER COLUMN jenis_izin TYPE VARCHAR(255) USING jenis_izin::text');
            DB::statement('ALTER TABLE pengajuan_izin DROP CONSTRAINT IF EXISTS pengajuan_izin_jenis_izin_check');
            return;
        }

        Schema::table('pengajuan_izin', function (Blueprint $table) {
            $table->string('jenis_izin')->change();
        });
    }
};  