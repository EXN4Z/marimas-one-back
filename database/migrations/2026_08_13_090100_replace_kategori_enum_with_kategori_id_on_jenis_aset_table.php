<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable()->after('nama')
                ->constrained('kategori')->restrictOnDelete();
        });

        // Backfill: samain jenis_aset.kategori (enum lama) ke kategori.kode
        // yang sepadan, lalu isi kategori_id-nya.
        $map = DB::table('kategori')->pluck('id', 'kode');
        foreach ($map as $kode => $id) {
            DB::table('jenis_aset')->where('kategori', $kode)->update(['kategori_id' => $id]);
        }

        // Jaga-jaga kalau ada baris yang somehow belum ke-set (mis. data
        // lama null) -- default ke 'aset_utama' biar kolomnya bisa dibikin
        // wajib diisi.
        DB::table('jenis_aset')->whereNull('kategori_id')
            ->update(['kategori_id' => $map['aset_utama']]);

        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable(false)->change();
            $table->dropColumn('kategori');
        });
    }

    public function down(): void
    {
        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->enum('kategori', ['aset_utama', 'kelengkapan'])
                ->default('aset_utama')
                ->after('nama');
        });

        $map = DB::table('kategori')->pluck('kode', 'id');
        foreach ($map as $id => $kode) {
            DB::table('jenis_aset')->where('kategori_id', $id)->update(['kategori' => $kode]);
        }

        Schema::table('jenis_aset', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kategori_id');
        });
    }
};
