<?php

namespace Database\Seeders;

use App\Models\JenisAset;
use Illuminate\Database\Seeder;

class JenisAsetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Laptop', 'Mouse', 'Monitor', 'Printer'] as $nama) {
            JenisAset::firstOrCreate(['nama' => $nama], ['kategori' => 'aset_utama']);
        }

        // Eks KelengkapanMasterSeeder -- kelengkapan (Tas, Charger, dst)
        // sekarang cuma beda kategori di jenis_aset, bukan tabel terpisah
        // lagi. 'Mouse' sengaja tetap di daftar aset_utama di atas (mouse
        // dilacak sebagai aset utama sendiri sejak dulu), jadi tidak
        // didaftar ulang di sini biar firstOrCreate tidak nabrak nama yang
        // sama dengan kategori beda.
        foreach (['Charger', 'Tas'] as $nama) {
            JenisAset::firstOrCreate(['nama' => $nama], ['kategori' => 'kelengkapan']);
        }
    }
}