<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtmachi bo'limlar / portal / proba miqdori modulini olib tashlash (menyu va ruxsatlar).
 *   php artisan db:seed --class=SampleIntakeModuleRemoveSeeder --force
 * «Buyurtmachi» roli faqat unga foydalanuvchi biriktirilmagan bo'lsa o'chiriladi.
 * Jadvallar/ustunlar uchun keyin (DIQQAT: bo'limlar, shifrlar, miqdorlar o'chadi):
 *   php artisan migrate:rollback --path=database/migrations/2026_10_13_110000_worksheet_kind_and_sample_consumption.php --force
 *   php artisan migrate:rollback --path=database/migrations/2026_10_13_100000_create_customer_departments_and_sample_quantity.php --force
 */
class SampleIntakeModuleRemoveSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            MenuItem::whereIn('key', ['portal', 'sample_balances', 'customer_departments'])->delete();

            $ids = Permission::whereIn('name', [
                'customer_departments.view', 'customer_departments.manage', 'sample_quantity.manage', 'portal.view',
            ])->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            Permission::whereIn('id', $ids)->delete();

            $role = Role::where('name', 'customer')->first();
            if ($role && ! DB::table('users')->where('role_id', $role->id)->exists()) {
                DB::table('permission_role')->where('role_id', $role->id)->delete();
                $role->delete();
            }
        });
    }
}
