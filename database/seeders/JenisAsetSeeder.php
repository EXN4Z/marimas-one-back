<?php

namespace Database\Seeders;

use App\Models\JenisAset;
use Illuminate\Database\Seeder;

class JenisAsetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Laptop', 'Mouse', 'Monitor', 'Printer'] as $nama) {
            JenisAset::firstOrCreate(['nama' => $nama]);
        }
    }
}
