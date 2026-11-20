<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** [Paket 38] P37 «Grafik jadvallari» izlarini tozalash */
class P38CleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_p37_traces_are_removed_and_routes_gone(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->assertFalse(Schema::hasTable('schedule_sheets'));

        // P37 o'rnatilgan bazani taqlid qilish
        Schema::create('schedule_sheets', fn (Blueprint $t) => $t->id());
        Schema::create('schedule_sheet_values', fn (Blueprint $t) => $t->id());
        $pid = DB::table('permissions')->insertGetId(['name' => 'schedule_sheets.view', 'module' => 'schedule_sheets', 'ability' => 'view',
            'group' => 'lab', 'label_uz' => 'x', 'label_ru' => 'x', 'label_en' => 'x', 'sort_order' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::where('name', 'operator')->first();
        $role->permissions()->syncWithoutDetaching([$pid]);
        DB::table('menu_items')->insert(['key' => 'schedule_sheets', 'title_uz' => 'x', 'title_ru' => 'x', 'title_en' => 'x', 'icon' => 'table',
            'route' => 'schedule-sheets', 'permission' => 'schedule_sheets.view', 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_11_19_100000_drop_schedule_sheets_tables.php'))->up();

        $this->assertFalse(Schema::hasTable('schedule_sheets'));
        $this->assertFalse(Schema::hasTable('schedule_sheet_values'));
        $this->assertSame(0, DB::table('permissions')->where('name', 'schedule_sheets.view')->count());
        $this->assertSame(0, DB::table('menu_items')->where('key', 'schedule_sheets')->count());
        $this->assertSame(0, DB::table('permission_role')->where('permission_id', $pid)->count());

        // ikkinchi marta — xato bermaydi
        (require database_path('migrations/2026_11_19_100000_drop_schedule_sheets_tables.php'))->up();

        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->getJson('/api/schedule-sheets')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/schedule-sheet-templates')->assertNotFound();
    }
}
