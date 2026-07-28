<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bersih-bersih sisa migration lama yang dobel/konflik (skema aset v1
     * dari 2026_07_20_*). Migration itu sempat jalan sebagian sebelum macet
     * pas nabrak migration duplikat, jadi tabelnya nyangkut di database
     * dengan skema lama yang gak dipakai controller/model manapun lagi.
     *
     * dropIfExists / IF EXISTS bikin ini aman dijalankan di database mana
     * pun -- baik yang udah kena masalah ini maupun database baru yang
     * bersih dari awal (gak ada efek apa-apa kalau tabelnya emang gak ada).
     */
    public function up(): void
    {
        DB::unprepared('drop trigger if exists trg_generate_kode_aset on aset');
        DB::unprepared('drop function if exists generate_kode_aset');

        // CASCADE biar urutan drop gak masalah walau ada foreign key
        // silang antar tabel-tabel lama ini (aset_kelengkapan, aset_penanganan,
        // aset_audit, aset_writeoff, aset_peminjaman semua nempel ke aset/supplier).
        DB::unprepared(
            'drop table if exists aset_writeoff, aset_audit, aset_penanganan, '
            . 'aset_kelengkapan, aset_peminjaman, aset, supplier cascade'
        );
    }

    public function down(): void
    {
        // Sengaja kosong: ini migration pembersihan satu arah, gak ada yang
        // perlu di-restore kalau di-rollback.
    }
};
