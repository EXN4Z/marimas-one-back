<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KelengkapanMasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Charger', 'Tas', 'Mouse'] as $nama) {
            DB::table('kelengkapan_master')->insertOrIgnore([
                'nama' => $nama,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
