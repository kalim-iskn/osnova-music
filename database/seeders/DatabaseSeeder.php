<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'demo@waveflow.local'],
            ['name' => 'Demo User', 'password' => 'password']
        );

        $this->call(DemoCatalogSeeder::class);
    }
}
