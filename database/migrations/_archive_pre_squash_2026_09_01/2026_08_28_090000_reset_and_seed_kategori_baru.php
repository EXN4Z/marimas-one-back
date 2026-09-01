<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 0 refactor "kategori bukan lagi penentu tipe Barang Utama/Kelengkapan":
 * kosongkan 2 baris dasar lama ("Barang Utama", "Kelengkapan") dari tabel
 * `kategori`, lalu seed 13 kategori barang yang sebenarnya (Bag, Baterai,
 * Case, Charger, Docking, Drawing Pad, Hdd External, Laptop, Modem,
 * Pointer, Proyektor, Scanner Barcode, Speaker) -- sumber daftar:
 * Data_Kategori_revisi.xlsx.
 *
 * PENTING soal urutan migrasi data:
 * `inventory.kategori_id` NOT NULL + restrictOnDelete ke `kategori`, jadi
 * kalau ada baris inventory yang masih nunjuk ke "Barang Utama"/
 * "Kelengkapan" lama, delete di bawah bakal gagal kena FK constraint.
 * Migration ini SENGAJA tidak melakukan remap otomatis -- per keputusan
 * saat pengerjaan, database masih fresh (belum ada inventory yang kepakai
 * kategori lama), jadi aman langsung dihapus. Guard di bawah (cek exists)
 * cuma jaring pengaman: kalau ternyata ada inventory yang nyangkut, migration
 * ini akan throw dengan pesan jelas alih-alih gagal diam-diam / kena FK error
 * yang membingungkan.
 *
 * Idempotent: insert kategori baru dicek dulu per nama (skip kalau sudah
 * ada), delete kategori lama pakai whereIn nama jadi aman dijalankan ulang.
 */
return new class extends Migration
{
    private const KATEGORI_LAMA = ['Barang Utama', 'Kelengkapan'];

    private const KATEGORI_BARU = [
        'Bag',
        'Baterai',
        'Case',
        'Charger',
        'Docking',
        'Drawing Pad',
        'Hdd External',
        'Laptop',
        'Modem',
        'Pointer',
        'Proyektor',
        'Scanner Barcode',
        'Speaker',
    ];

    public function up(): void
    {
        $idKategoriLama = DB::table('kategori')
            ->whereIn('nama', self::KATEGORI_LAMA)
            ->pluck('id');

        if ($idKategoriLama->isNotEmpty()) {
            $jumlahInventoryNyangkut = DB::table('inventory')
                ->whereIn('kategori_id', $idKategoriLama)
                ->count();

            if ($jumlahInventoryNyangkut > 0) {
                throw new \RuntimeException(
                    "Migration reset_and_seed_kategori_baru dibatalkan: masih ada "
                    . "{$jumlahInventoryNyangkut} baris inventory yang kategori_id-nya "
                    . "nunjuk ke kategori lama ('Barang Utama'/'Kelengkapan'). Remap dulu "
                    . "baris-baris itu ke salah satu dari 13 kategori baru sebelum migration "
                    . "ini dijalankan."
                );
            }

            DB::table('kategori')->whereIn('id', $idKategoriLama)->delete();
        }

        foreach (self::KATEGORI_BARU as $nama) {
            $ada = DB::table('kategori')->where('nama', $nama)->exists();

            if (!$ada) {
                DB::table('kategori')->insert([
                    'nama'       => $nama,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('kategori')->whereIn('nama', self::KATEGORI_BARU)->delete();

        foreach (self::KATEGORI_LAMA as $nama) {
            $ada = DB::table('kategori')->where('nama', $nama)->exists();

            if (!$ada) {
                DB::table('kategori')->insert([
                    'nama'       => $nama,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};