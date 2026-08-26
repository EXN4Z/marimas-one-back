<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menggabungkan 2 tabel jadi 1:
     * - `kategori` (dulu cuma 2 baris fix "Barang Utama"/"Kelengkapan",
     *   read-only, dibedain lewat kolom `kode` enum-style) DIHAPUS
     *   sifat read-only-nya -- sekarang dikelola admin lewat CRUD biasa,
     *   sama kayak `master_kategori` dulu.
     * - `master_kategori` (jenis/tipe barang "Laptop", "Proyektor", dst,
     *   nempel ke salah satu `kategori` lewat kategori_id) DIHAPUS TOTAL.
     *   Klasifikasi Barang Utama/Kelengkapan gak lagi lewat 2 tingkat
     *   (inventory -> master_kategori -> kategori), sekarang langsung
     *   inventory -> kategori 1 tingkat aja, dicek lewat `kategori.nama`
     *   ("Barang Utama" / "Kelengkapan") -- BUKAN `kategori.kode` lagi
     *   (kolom itu sekarang cuma abbreviation opsional ala master_kategori
     *   lama, mis. "LAPTOP", "CHRG", bukan enum wajib unik).
     */
    public function up(): void
    {
        // Pastikan 2 baris dasar ada (nama INI dipakai kode program buat
        // klasifikasi Barang Utama/Kelengkapan -- lihat Kategori::isBarangUtama()
        // & Inventory::isBarangUtama()/isKelengkapan()). Kalau instalasi lama
        // udah punya baris ini (nama sama persis), gak dobel insert.
        foreach (['Barang Utama', 'Kelengkapan'] as $namaDasar) {
            $ada = DB::table('kategori')->where('nama', $namaDasar)->exists();

            if (!$ada) {
                DB::table('kategori')->insert([
                    'nama' => $namaDasar,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Tambah kategori_id di inventory dulu (nullable), backfill dari
        // master_kategori.kategori_id, baru drop master_kategori_id +
        // tabel master_kategori-nya.
        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable()
                ->after('parent_id')
                ->constrained('kategori')
                ->nullOnDelete();
        });

        $petaMasterKategoriKeKategori = DB::table('master_kategori')->pluck('kategori_id', 'id');

        foreach ($petaMasterKategoriKeKategori as $masterKategoriId => $kategoriId) {
            DB::table('inventory')
                ->where('master_kategori_id', $masterKategoriId)
                ->update(['kategori_id' => $kategoriId]);
        }

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_kategori_id');
        });

        Schema::dropIfExists('master_kategori');

        // kategori.kode lama (enum-style, unique, wajib) diganti jadi
        // abbreviation opsional ala master_kategori.kode dulu (nullable,
        // max 10, gak unique -- boleh kosong, boleh sama antar baris).
        Schema::table('kategori', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
        });

        Schema::table('kategori', function (Blueprint $table) {
            $table->string('kode', 10)->nullable()->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('kategori', function (Blueprint $table) {
            $table->dropColumn('kode');
        });

        Schema::table('kategori', function (Blueprint $table) {
            $table->string('kode')->unique()->after('nama');
        });

        Schema::create('master_kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('kode', 10)->nullable();
            $table->foreignId('kategori_id')->constrained('kategori')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->foreignId('master_kategori_id')->nullable()
                ->after('parent_id')
                ->constrained('master_kategori')
                ->nullOnDelete();
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kategori_id');
        });
    }
};