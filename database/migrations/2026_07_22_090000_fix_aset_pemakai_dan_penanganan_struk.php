<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // aset_pemakai butuh no_struk buat bukti serah-terima & pengembalian
        // (model + frontend udah pakai field ini, kolomnya belum pernah dibuat).
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->string('no_struk_penerimaan')->nullable()->after('nomor_penerimaan');
            $table->string('no_struk_pengembalian')->nullable()->after('nomor_pengembalian');
        });

        // aset_penanganan sebelumnya nempel ke aset_peminjaman (sistem lama,
        // gak kepake). Sistem aktif sekarang aset_pemakai, jadi pindah FK-nya.
        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dropForeign(['aset_peminjaman_id']);
            $table->dropColumn('aset_peminjaman_id');

            $table->foreignId('aset_pemakai_id')->nullable()->after('aset_id')
                ->constrained('aset_pemakai')->nullOnDelete();
        });

        // aset_peminjaman(_kelengkapan) & aset_perbaikan: sistem lama yang udah
        // digantiin aset_pemakai + aset_penanganan, gak ada di routes/model
        // manapun lagi. Bersihin biar gak dobel & bingung.
        Schema::dropIfExists('aset_peminjaman_kelengkapan');
        Schema::dropIfExists('aset_peminjaman');
        Schema::dropIfExists('aset_perbaikan');
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropColumn(['no_struk_penerimaan', 'no_struk_pengembalian']);
        });

        Schema::table('aset_penanganan', function (Blueprint $table) {
            $table->dropForeign(['aset_pemakai_id']);
            $table->dropColumn('aset_pemakai_id');

            $table->foreignId('aset_peminjaman_id')->nullable();
        });

        // aset_peminjaman / aset_perbaikan sengaja gak direstore di down():
        // tabel lama yang dihapus, kalau perlu balikin harus dari migration aslinya.
    }
};
