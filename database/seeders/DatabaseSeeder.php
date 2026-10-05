<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            LaboratorySeeder::class,
            LaboratoryGroupExtraSeeder::class,
            ElementSeeder::class,
            MenuSeeder::class,
            UserSeeder::class,
            MonitoringPointSeeder::class,
            TestProfileSeeder::class,
        ]);
    }
}
