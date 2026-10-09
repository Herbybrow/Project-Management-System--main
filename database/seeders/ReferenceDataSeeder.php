<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(fn () => $this->call([PermissionSeeder::class, RoleSeeder::class]));
    }
}
