<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status 'rusak' udah dihapus total dari alur (gak dipakai lagi sejak
 * repair workflow sekarang menunggu_perbaikan -> diperbaiki -> selesai
 * (diperbaiki/rusak_berat), lihat InventoryPenangananController). Endpoint
 * /inventory/kelengkapan/rusak yang dulu nampilin daftar ini juga sudah
 * dihapus (gak ada caller di frontend).
 *
 * Migration ini cuma jaga-jaga: kalau ada baris lama yang masih nyangkut
 * di status 'rusak' (dari sebelum repair workflow sekarang ada), pindahin
 * ke 'menunggu_perbaikan' supaya tetap kelihatan & bisa ditindaklanjuti
 * admin lewat alur InventoryPenanganan yang sekarang, bukan hilang begitu
 * saja dari semua tab/filter status.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory')
            ->where('status', 'rusak')
            ->update(['status' => 'menunggu_perbaikan']);
    }

    public function down(): void
    {
        // Gak bisa dibalikin dengan aman -- gak ada cara bedain baris yang
        // memang aslinya 'rusak' vs yang emang udah 'menunggu_perbaikan'
        // dari alur normal. Sengaja no-op.
    }
};