<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 13 kategori barang final (sumber: Data_Kategori_revisi.xlsx),
 * hasil rapian dari riwayat migrasi lama (seed 2 kategori dasar "Barang
 * Utama"/"Kelengkapan" -> reset & seed 13 kategori sebenarnya).
 * Idempotent: skip per nama kalau sudah ada.
 */
return new class extends Migration
{
    private const KATEGORI = [
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
        foreach (self::KATEGORI as $nama) {
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
        DB::table('kategori')->whereIn('nama', self::KATEGORI)->delete();
    }
};
