<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop tabel `aset_kelengkapan` -- sudah gak dipakai sejak Kelengkapan
 * digabung jadi baris `inventory` biasa (dibedain dari Barang Utama lewat
 * `kategori_id` + nempel ke induknya lewat `parent_id`), bukan tabel
 * terpisah lagi. Migration `update_all_aset_table` sudah mindahin FK-nya
 * (inventory_pemakai, inventory_penanganan, inventory_writeoff) ke
 * `inventory_id` dan drop kolom `aset_kelengkapan_id`, tapi tabel
 * `aset_kelengkapan` itu sendiri gak pernah ke-drop -- jadi ketinggalan
 * sebagai tabel orphan, sama kasusnya kayak `master_kategori`.
 *
 * Semua sisa referensi "aset_kelengkapan"/"AsetKelengkapan" di kode aktif
 * (Inventory.php, InventoryController.php, InventoryBuktiImport.php,
 * AsetKelengkapanKerusakanDilaporkan) cuma komentar historis ("eks ...")
 * atau nama class notifikasi -- gak ada query aktif ke tabel ini lagi,
 * jadi aman di-drop.
 *
 * Pakai dropIfExists supaya aman dijalankan berapa kali pun / di kondisi
 * DB manapun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('aset_kelengkapan');
    }

    public function down(): void
    {
        // Skema lama aset_kelengkapan sudah berubah-ubah lewat banyak
        // migration (add/drop kolom bertahap sejak Aug 12), jadi gak
        // direkonstruksi persis di sini -- kalau perlu rollback total,
        // restore dari backup DB sebelum migration ini dijalankan.
    }
};