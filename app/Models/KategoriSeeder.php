<?php

namespace Database\Seeders;

use App\Models\Kategori;
use Illuminate\Database\Seeder;

class KategoriSeeder extends Seeder
{
    public function run(): void
    {
        Kategori::firstOrCreate(['kode' => 'aset_utama'], ['nama' => 'Barang']);
        Kategori::firstOrCreate(['kode' => 'kelengkapan'], ['nama' => 'Kelengkapan']);
    }
}