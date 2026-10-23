<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database: the pre-ingested documents only.
     * Conversations and messages are live data and are never seeded.
     */
    public function run(): void
    {
        $this->call(DocumentSeeder::class);
    }
}
