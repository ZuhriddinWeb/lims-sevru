<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ishchi varaqlar modulini olib tashlash (menyu va ruxsatlar):
 *   php artisan db:seed --class=WorksheetModuleRemoveSeeder
 *
 * Jadvallarni o'chirish uchun keyin (avval partiya migratsiyasi, keyin asosiysi):
 *   php artisan migrate:rollback --path=database/migrations/2026_10_09_100000_worksheet_batches.php
 *   php artisan migrate:rollback --path=database/migrations/2026_10_08_100000_create_worksheet_tables.php
 */
class WorksheetModuleRemoveSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            MenuItem::whereIn('key', ['worksheets', 'worksheet_templates'])->delete();

            $ids = Permission::whereIn('module', ['worksheets', 'worksheet_templates'])->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            Permission::whereIn('id', $ids)->delete();
        });
    }
}
