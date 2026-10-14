<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Smenalar sozlamasi va xodimlar faoliyatini olib tashlash (menyu va ruxsatlar).
 *   php artisan db:seed --class=InsightsModuleRemoveSeeder --force
 * Jadval va imzo soati ustuni uchun keyin (DIQQAT: smenalar va imzo soatlari o'chadi):
 *   php artisan migrate:rollback --path=database/migrations/2026_10_14_100000_create_lab_shifts_and_signoff_time.php --force
 */
class InsightsModuleRemoveSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            MenuItem::whereIn('key', ['lab_shifts', 'staff_activity'])->delete();
            $ids = Permission::whereIn('name', ['shifts.manage', 'staff_activity.view'])->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            Permission::whereIn('id', $ids)->delete();
        });
    }
}
