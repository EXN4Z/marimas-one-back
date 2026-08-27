<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kelengkapan (charger, tas, kabel, dll) gak punya sisi "software", jadi gak
 * masuk akal disuruh milih Hardware/Software pas lapor kerusakan. Migrasi ini
 * nambah 3 opsi baru khusus Kelengkapan: tidak_berfungsi, hancur,
 * terputus_sobek. Opsi lama (software, hardware) tetap ada, dipakai khusus
 * Barang Utama. Mana yang boleh dipilih tergantung kategori inventory --
 * divalidasi di InventoryPenangananController, bukan di level kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_penanganan MODIFY jenis_kerusakan ENUM('software','hardware','tidak_berfungsi','hancur','terputus_sobek') NOT NULL");
        }
        // sqlite gak enforce enum di level kolom (cuma text biasa), jadi gak perlu diapa-apain di sana.
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_penanganan MODIFY jenis_kerusakan ENUM('software','hardware') NOT NULL");
        }
    }
};