<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Semua step di-guard (cek exists dulu) karena migration ini pernah
     * kejalanin sebagian di beberapa environment sebelum di-edit ulang
     * (mis. kolom `kode` udah lebih dulu kehapus manual/lewat migration
     * lama). Jadi migration ini aman dijalanin ulang di DB kondisi apapun
     * -- tiap step cuma jalan kalau memang belum diterapkan.
     */
    public function up(): void
    {
        if (Schema::hasColumn('kategori', 'kode')) {
            Schema::table('kategori', function (Blueprint $table) {
                $table->dropColumn('kode');
            });
        }

        if (!Schema::hasColumn('inventory', 'kategori_id')) {
            Schema::table('inventory', function (Blueprint $table) {
                $table->foreignId('kategori_id')->nullable()->constrained('kategori')->restrictOnDelete();
            });
        }

        // Backfill kategori_id dari golongan master_kategori lama SEBELUM
        // master_kategori_id di-drop, biar relasi kategori pada baris
        // inventory existing gak ilang begitu aja. Peta: master_kategori.id
        // -> master_kategori.kategori_id (golongan Barang Utama/Kelengkapan
        // jenis itu), lalu di-apply ke tiap inventory yang masih nempel ke
        // master_kategori tsb lewat master_kategori_id. Cuma jalan kalau
        // kedua sumbernya masih ada (master_kategori_id belum kehapus).
        if (Schema::hasTable('master_kategori') && Schema::hasColumn('inventory', 'master_kategori_id')) {
            $petaMasterKategoriKeKategori = DB::table('master_kategori')->pluck('kategori_id', 'id');

            foreach ($petaMasterKategoriKeKategori as $masterKategoriId => $kategoriId) {
                DB::table('inventory')
                    ->where('master_kategori_id', $masterKategoriId)
                    ->update(['kategori_id' => $kategoriId]);
            }
        }

        if (Schema::hasColumn('inventory', 'master_kategori_id')) {
            Schema::table('inventory', function (Blueprint $table) {
                $table->dropColumn('master_kategori_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};