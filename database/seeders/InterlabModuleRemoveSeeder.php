<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Laboratoriyalararo tekshiruv modulini olib tashlash (menyu va ruxsatlar).
 *   php artisan db:seed --class=InterlabModuleRemoveSeeder --force
 * Jadvallar uchun keyin (avval invites, keyin asosiy):
 *   php artisan migrate:rollback --path=database/migrations/2026_10_12_110000_create_interlab_invites_table.php --force
 *   php artisan migrate:rollback --path=database/migrations/2026_10_12_100000_create_interlab_tables.php --force
 * DIQQAT: jadvallar o'chirilsa yo'llanmalar va natijalar tarixi ham o'chadi.
 */
class InterlabModuleRemoveSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            MenuItem::whereIn('key', ['interlab', 'interlab_network'])->delete();
            $ids = Permission::where('name', 'like', 'interlab.%')->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            Permission::whereIn('id', $ids)->delete();
        });
    }
}
