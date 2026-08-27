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
 *
 * Production pakai PostgreSQL (bukan MySQL) -- di Postgres, "enum" kolom ini
 * sebenarnya cuma CHECK CONSTRAINT biasa, namanya masih
 * `aset_penanganan_jenis_kerusakan_check` (sisa sebelum tabel di-rename dari
 * `aset_penanganan` ke `inventory_penanganan` -- constraint gak ikut
 * ke-rename otomatis pas Schema::rename() dulu).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS aset_penanganan_jenis_kerusakan_check');
            DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS inventory_penanganan_jenis_kerusakan_check');
            DB::statement("ALTER TABLE inventory_penanganan ADD CONSTRAINT inventory_penanganan_jenis_kerusakan_check CHECK (jenis_kerusakan IN ('software','hardware','tidak_berfungsi','hancur','terputus_sobek'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE inventory_penanganan MODIFY jenis_kerusakan ENUM('software','hardware','tidak_berfungsi','hancur','terputus_sobek') NOT NULL");
        }
        // sqlite gak enforce enum/check di level kolom (cuma text biasa), jadi gak perlu diapa-apain di sana.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE inventory_penanganan DROP CONSTRAINT IF EXISTS inventory_penanganan_jenis_kerusakan_check');
            DB::statement("ALTER TABLE inventory_penanganan ADD CONSTRAINT inventory_penanganan_jenis_kerusakan_check CHECK (jenis_kerusakan IN ('software','hardware'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE inventory_penanganan MODIFY jenis_kerusakan ENUM('software','hardware') NOT NULL");
        }
    }
};