<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class DummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
        'name' => 'Dummy',
        'email' => 'test@example.com',
        'password' => bcrypt('dummyPassword'),
        'phone' => '0819853467',
        'role' => 'guest',
        ]);
    }
}
