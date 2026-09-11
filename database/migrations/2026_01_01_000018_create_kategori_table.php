<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi SQUASHED (ke-2). Riwayat aslinya lewat 3 generasi tabel kategori
 * (enum di jenis_aset -> tabel kategori+jenis_aset -> kategori+master_kategori
 * 2 tingkat) sebelum akhirnya disederhanakan jadi 1 tabel flat tanpa kolom
 * `kode` sama sekali -- lihat App\Models\MasterData\Kategori. Kategori
 * sekarang murni label bebas yang dikelola admin, tidak lagi menentukan
 * golongan/tipe barang (itu urusan Inventory::parent_id).
 *
 * Seed 13 kategori final (sumber: Data_Kategori_revisi.xlsx) yang dulu
 * ada di migration terpisah (seed_kategori_baru) sekarang digabung
 * langsung di sini.
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
        Schema::create('kategori', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->timestamps();
        });

        foreach (self::KATEGORI as $nama) {
            DB::table('kategori')->insert([
                'nama'       => $nama,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori');
    }
};
