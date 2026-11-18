<?php

namespace Tests\Feature;

use App\Models\CustomerDepartment;
use App\Models\Role;
use App\Models\Sample;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SampleIntakeModuleSeeder;
use Database\Seeders\SampleRequestModuleSeeder;
use Database\Seeders\ScheduleModuleSeeder;
use Database\Seeders\ScheduleSheetModuleSeeder;
use Database\Seeders\SvodkaModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** [Paket 37] Grafik jadvallari (Excel ko'rinishida) */
class P37ScheduleSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $head;
    private User $operator;
    private User $viewer;
    private User $customer;
    private User $customer2;
    private CustomerDepartment $dept;
    private int $au;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 07:30'));
        $this->seed([PermissionSeeder::class, RoleSeeder::class, SampleIntakeModuleSeeder::class, SampleRequestModuleSeeder::class,
            SvodkaModuleSeeder::class, ScheduleModuleSeeder::class, ScheduleSheetModuleSeeder::class]);
        $lab = DB::table('laboratories')->insertGetId(['code' => 'TL', 'name_ru' => 'ЦЗЛ', 'name_uz' => 'MZL', 'name_en' => 'CL', 'created_at' => now(), 'updated_at' => now()]);
        $role = fn ($n) => Role::where('name', $n)->first()->id;
        $this->admin = User::factory()->create(['role_id' => $role('admin'), 'laboratory_id' => $lab]);
        $this->head = User::factory()->create(['role_id' => $role('lab_head'), 'laboratory_id' => $lab]);
        $this->operator = User::factory()->create(['role_id' => $role('operator'), 'laboratory_id' => $lab]);
        $this->viewer = User::factory()->create(['role_id' => $role('viewer'), 'laboratory_id' => $lab]);
        $this->customer = User::factory()->create(['role_id' => $role('customer')]);
        $this->customer2 = User::factory()->create(['role_id' => $role('customer')]);
        $this->dept = CustomerDepartment::create(['code' => 'OTK', 'name' => 'OTK GMZ-3', 'is_active' => true]);
        $dept2 = CustomerDepartment::create(['code' => 'TTG', 'name' => 'TTG', 'is_active' => true]);
        DB::table('customer_department_user')->insert([
            ['customer_department_id' => $this->dept->id, 'user_id' => $this->customer->id],
            ['customer_department_id' => $dept2->id, 'user_id' => $this->customer2->id],
        ]);
        $this->au = DB::table('elements')->insertGetId(['symbol' => 'Au', 'name_ru' => 'Золото', 'name_uz' => 'Oltin', 'name_en' => 'Gold', 'default_unit' => 'g/t', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('laboratory_groups')->update(['is_active' => false]);

        $this->actingAs($this->head)->putJson("/api/schedule-board/settings/{$this->dept->id}", ['enabled' => true, 'late_minutes' => 30])->assertOk();
        foreach (['08:00', '10:00', '12:00'] as $t) {
            $this->actingAs($this->customer)->postJson('/api/portal/schedules', [
                'name' => "KSP $t", 'time' => $t, 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'payload' => ['items' => [['customer_code' => 'KSP', 'state_of_matter' => 'solid', 'element_ids' => [$this->au], 'quantity' => 100, 'quantity_unit' => 'g']]],
            ])->assertCreated();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function columns(): array
    {
        return [
            ['key' => 'zich', 'kind' => 'input', 'title' => 'KSP zichligi', 'decimals' => 3],
            ['key' => 'p015', 'kind' => 'input', 'title' => '+0,15', 'unit' => '%', 'decimals' => 1],
            ['key' => 'au', 'kind' => 'result', 'title' => 'Au', 'unit' => 'g/t', 'item' => 1, 'element_id' => $this->au, 'decimals' => 2],
            ['key' => 'mtb', 'kind' => 'formula', 'title' => 'MTB', 'formula' => 'round(zich * p015 / 10, 2)', 'decimals' => 2],
            ['key' => 'tayyor', 'kind' => 'formula', 'title' => 'TAYYOR SINF', 'formula' => '100 - p015', 'decimals' => 1],
            ['key' => 'mtb_kun', 'kind' => 'average', 'title' => "MTB (o'rtacha)", 'source' => 'mtb', 'mode' => 'day'],
            ['key' => 'p015_run', 'kind' => 'average', 'title' => "+0,15 (o'rtacha)", 'source' => 'p015', 'mode' => 'running', 'decimals' => 1],
        ];
    }

    private function makeSheet(): int
    {
        return $this->actingAs($this->admin)->postJson('/api/schedule-sheet-templates', [
            'department_id' => $this->dept->id, 'name' => 'KSP bo\'tana', 'columns' => $this->columns(),
        ])->assertCreated()->json('id');
    }

    public function test_template_validation_and_permissions(): void
    {
        $this->actingAs($this->head)->getJson('/api/schedule-sheet-templates')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/schedule-sheet-templates/options')->assertOk()->assertJsonPath('elements.0.symbol', 'Au');

        $bad = $this->columns();
        $bad[3]['formula'] = 'zich * nomalum';
        $this->actingAs($this->admin)->postJson('/api/schedule-sheet-templates', ['department_id' => $this->dept->id, 'name' => 'X', 'columns' => $bad])
            ->assertStatus(422)->assertJsonValidationErrors('columns.3.formula');
        $bad = $this->columns();
        $bad[3]['formula'] = 'mtb_kun * 2'; // o'rtacha ustunni formulada ishlatib bo'lmaydi
        $this->actingAs($this->admin)->postJson('/api/schedule-sheet-templates', ['department_id' => $this->dept->id, 'name' => 'X', 'columns' => $bad])
            ->assertStatus(422)->assertJsonValidationErrors('columns.3.formula');
        $cyc = [
            ['key' => 'a', 'kind' => 'formula', 'title' => 'A', 'formula' => 'b + 1'],
            ['key' => 'b', 'kind' => 'formula', 'title' => 'B', 'formula' => 'a + 1'],
        ];
        $this->actingAs($this->admin)->postJson('/api/schedule-sheet-templates', ['department_id' => $this->dept->id, 'name' => 'X', 'columns' => $cyc])
            ->assertStatus(422)->assertJsonValidationErrors('columns');
        $dup = $this->columns();
        $dup[1]['key'] = 'zich';
        $this->actingAs($this->admin)->postJson('/api/schedule-sheet-templates', ['department_id' => $this->dept->id, 'name' => 'X', 'columns' => $dup])
            ->assertStatus(422)->assertJsonValidationErrors('columns.1.key');

        $id = $this->makeSheet();
        $this->assertCount(7, $this->actingAs($this->admin)->getJson('/api/schedule-sheet-templates')->json('data.0.columns'));

        // ko'rish: viewer (rahbariyat) — ha, kiritish — yo'q
        $this->actingAs($this->viewer)->getJson("/api/schedule-sheets/$id")->assertOk()->assertJsonPath('can_edit', false);
        $this->actingAs($this->viewer)->putJson("/api/schedule-sheets/$id/cell", ['run_id' => 1, 'key' => 'zich', 'value' => 1])->assertForbidden();
        $this->actingAs($this->customer)->getJson('/api/schedule-sheets')->assertForbidden();
    }

    public function test_grid_formulas_and_averages(): void
    {
        $id = $this->makeSheet();
        $g = $this->actingAs($this->operator)->getJson("/api/schedule-sheets/$id")->assertOk()->json();
        $this->assertCount(3, $g['rows']);
        $this->assertSame(['08:00', '10:00', '12:00'], array_column($g['rows'], 'time'));
        $this->assertTrue($g['can_edit']);
        [$r1, $r2, $r3] = array_column($g['rows'], 'run_id');

        $put = fn ($run, $k, $v) => $this->actingAs($this->operator)->putJson("/api/schedule-sheets/$id/cell", ['run_id' => $run, 'key' => $k, 'value' => $v]);
        $put($r1, 'zich', '1,45')->assertOk();
        $put($r1, 'p015', '20')->assertOk();
        $put($r2, 'zich', '1.5')->assertOk();
        $g = $put($r2, 'p015', '30')->assertOk()->json();
        $put($r1, 'mtb', '5')->assertStatus(422);           // formula — kiritilmaydi
        $put($r1, 'zich', 'abc')->assertStatus(422);

        [$a, $b, $c] = array_column($g['rows'], 'values');
        $this->assertEquals(1.45, $a['zich']);
        $this->assertEquals(2.9, $a['mtb']);                // 1.45*20/10
        $this->assertEquals(80, $a['tayyor']);
        $this->assertEquals(4.5, $b['mtb']);
        $this->assertEquals(3.7, $a['mtb_kun']);            // (2.9+4.5)/2 — kun bo'yicha, hamma qatorda bir xil
        $this->assertEquals(3.7, $c['mtb_kun']);
        $this->assertEquals(20, $a['p015_run']);            // shu qatorgacha
        $this->assertEquals(25, $b['p015_run']);
        $this->assertEquals(25, $c['p015_run']);
        $this->assertNull($c['mtb']);

        // bo'sh qiymat — o'chiradi
        $g = $put($r2, 'p015', '')->assertOk()->json();
        $this->assertNull($g['rows'][1]['values']['mtb']);
        $this->assertEquals(2.9, $g['rows'][0]['values']['mtb_kun']);

        // Excel — sariq ustunlar
        $res = $this->actingAs($this->viewer)->get("/api/schedule-sheets/$id/export?date=2026-11-18")->assertOk();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($res->baseResponse->getFile()->getPathname()));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('MTB', $zip->getFromName('xl/sharedStrings.xml') ?: $xml);
        $this->assertStringContainsString('s="3"', $xml);
    }

    public function test_result_column_from_sample_and_portal_isolation(): void
    {
        $id = $this->makeSheet();
        $run = $this->actingAs($this->operator)->getJson("/api/schedule-sheets/$id")->json('rows.0');
        // natija yo'q — qo'lda kiritsa bo'ladi
        $this->actingAs($this->operator)->putJson("/api/schedule-sheets/$id/cell", ['run_id' => $run['run_id'], 'key' => 'au', 'value' => '9'])->assertOk()
            ->assertJsonPath('rows.0.values.au', 9);

        Carbon::setTestNow(Carbon::parse('2026-11-18 08:05'));
        $this->actingAs($this->operator)->postJson("/api/schedule-board/{$run['run_id']}/arrive", ['handed_over_by' => 'Rahimov', 'received_by' => $this->head->id])->assertOk();
        $sample = Sample::sole();
        DB::table('sample_elements')->where('sample_id', $sample->id)->update(['result_value' => 2.345, 'status' => 'done']);

        $g = $this->actingAs($this->operator)->getJson("/api/schedule-sheets/$id")->json();
        $this->assertEquals(2.35, $g['rows'][0]['values']['au']);       // namunadan, 2 xona
        $this->assertSame('sample', $g['rows'][0]['meta']['au']['from']);
        $this->actingAs($this->operator)->putJson("/api/schedule-sheets/$id/cell", ['run_id' => $run['run_id'], 'key' => 'au', 'value' => '1'])
            ->assertStatus(422);

        // buyurtmachi: o'z bo'linmasi — ko'radi, kirita olmaydi; boshqa bo'linma — yo'q
        $this->assertContains('portal-schedule-sheets', array_column($this->actingAs($this->customer)->getJson('/api/menu')->json(), 'route'));
        $this->assertNotContains('portal-schedule-sheets', array_column($this->actingAs($this->customer2)->getJson('/api/menu')->json(), 'route'));
        $this->assertCount(1, $this->actingAs($this->customer)->getJson('/api/portal/schedule-sheets')->json('data'));
        $this->actingAs($this->customer)->getJson("/api/portal/schedule-sheets/$id")->assertOk()->assertJsonPath('can_edit', false)->assertJsonCount(3, 'rows');
        $this->assertCount(0, $this->actingAs($this->customer2)->getJson('/api/portal/schedule-sheets')->json('data'));
        $this->actingAs($this->customer2)->getJson("/api/portal/schedule-sheets/$id")->assertNotFound();
        $this->actingAs($this->customer)->get("/api/portal/schedule-sheets/$id/export")->assertOk();
    }
}
