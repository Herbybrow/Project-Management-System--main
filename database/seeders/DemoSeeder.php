<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataGuard::check();

        DB::transaction(function () {
            $this->call([
                ReferenceDataSeeder::class,
                UserSeeder::class,
                DemoUserSeeder::class,
                UserBulkSeeder::class,
                ProjectSeeder::class,
            ]);
        });
    }
}
