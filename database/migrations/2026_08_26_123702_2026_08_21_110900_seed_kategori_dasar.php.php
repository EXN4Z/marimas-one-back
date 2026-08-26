<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed 2 baris dasar kategori: "Barang Utama" & "Kelengkapan". Wajib ada
 * karena logic sistem (Kategori::isBarangUtama()/isKelengkapan(),
 * Inventory::isBarangUtama()/isKelengkapan()/scopeBarangUtama()/
 * scopeKelengkapan(), InventoryController::validasiParent(),
 * InventoryBuktiImport) cek golongan lewat `kategori.nama` PERSIS sama
 * 2 string ini -- bukan enum/kode lagi (lihat dokumen migrasi Master
 * Kategori -> Kategori).
 *
 * Insert dicek dulu (idempotent) biar aman dijalankan di instalasi yang
 * kebetulan sudah punya baris ini lewat jalur lain (mis. di-input manual
 * admin sebelum migration ini sempat jalan).
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        DB::table('kategori')->whereIn('nama', ['Barang Utama', 'Kelengkapan'])->delete();
    }
};