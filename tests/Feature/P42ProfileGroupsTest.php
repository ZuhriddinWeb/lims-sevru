<?php

namespace Tests\Feature;

use App\Models\CustomerDepartment;
use App\Models\Role;
use App\Models\Sample;
use App\Models\SampleRequest;
use App\Models\ScheduleRun;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SampleIntakeModuleSeeder;
use Database\Seeders\SampleRequestModuleSeeder;
use Database\Seeders\ScheduleModuleSeeder;
use Database\Seeders\SvodkaModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** [Paket 42] Profilga mas'ul guruhlar, namunaga guruh, buyurtmani guruhlar bo'yicha bo'lish, grafikda hisobot kuni */
class P42ProfileGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $head;
    private User $customer;
    private CustomerDepartment $dept;
    private int $lab;
    private int $lab2;
    private int $au;
    private int $ss;
    private int $g1;
    private int $g2;
    private int $g3;
    private int $gOther;
    private int $point;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 06:00'));
        $this->seed([PermissionSeeder::class, RoleSeeder::class, SampleIntakeModuleSeeder::class,
            SampleRequestModuleSeeder::class, SvodkaModuleSeeder::class, ScheduleModuleSeeder::class]);
        $now = ['created_at' => now(), 'updated_at' => now()];
        $this->lab = DB::table('laboratories')->insertGetId(['code' => 'TL', 'name_ru' => 'ЦЗЛ', 'name_uz' => 'MZL', 'name_en' => 'CL'] + $now);
        $this->lab2 = DB::table('laboratories')->insertGetId(['code' => 'TL2', 'name_ru' => 'Б', 'name_uz' => 'B', 'name_en' => 'B'] + $now);
        DB::table('laboratory_groups')->update(['is_active' => false]);
        $g = fn ($code, $lab) => DB::table('laboratory_groups')->insertGetId(['laboratory_id' => $lab, 'code' => $code, 'name_uz' => $code, 'name_ru' => $code, 'name_en' => $code, 'kind' => 'executor', 'is_active' => true] + $now);
        $this->g1 = $g('NTG', $this->lab);
        $this->g2 = $g('FNG', $this->lab);
        $this->g3 = $g('ZAX', $this->lab);
        $this->gOther = $g('BOSHQA', $this->lab2);

        $role = fn ($n) => Role::where('name', $n)->first()->id;
        $this->admin = User::factory()->create(['name' => 'Admin', 'role_id' => $role('admin'), 'laboratory_id' => $this->lab]);
        $this->head = User::factory()->create(['name' => 'Bosh', 'role_id' => $role('lab_head'), 'laboratory_id' => $this->lab]);
        $this->customer = User::factory()->create(['name' => 'OTK vakil', 'role_id' => $role('customer')]);
        $this->dept = CustomerDepartment::create(['code' => 'OTK', 'name' => 'OTK', 'is_active' => true]);
        DB::table('customer_department_user')->insert(['customer_department_id' => $this->dept->id, 'user_id' => $this->customer->id]);
        $el = fn ($s) => DB::table('elements')->insertGetId(['symbol' => $s, 'name_ru' => $s, 'name_uz' => $s, 'name_en' => $s, 'default_unit' => 'g/t', 'is_active' => true] + $now);
        $this->au = $el('Au');
        $this->ss = $el('Ss');
        $sec = DB::table('svodka_sections')->insertGetId(['customer_department_id' => $this->dept->id, 'title' => 'AU', 'sort_order' => 1] + $now);
        $this->point = DB::table('svodka_points')->insertGetId(['customer_department_id' => $this->dept->id, 'section_id' => $sec, 'name' => 'K SAF', 'sort_order' => 1] + $now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(string $code, array $groupIds, int $element): int
    {
        return (int) $this->actingAs($this->admin)->postJson('/api/test-profiles', [
            'code' => $code, 'name_ru' => $code, 'name_uz' => $code, 'name_en' => $code, 'laboratory_id' => $this->lab,
            'element_ids' => [$element], 'group_ids' => $groupIds, 'is_active' => true,
        ])->assertCreated()->json('id');
    }

    public function test_profile_groups_are_ordered_and_limited_to_its_laboratory(): void
    {
        $id = $this->profile('AU', [$this->g2, $this->g1], $this->au);
        $row = collect($this->actingAs($this->admin)->getJson('/api/test-profiles?all=1')->json())->firstWhere('id', $id);
        $this->assertSame([$this->g2, $this->g1], array_column($row['groups'], 'id'));

        $this->actingAs($this->admin)->putJson("/api/test-profiles/$id", [
            'code' => 'AU', 'name_ru' => 'AU', 'name_uz' => 'AU', 'name_en' => 'AU', 'laboratory_id' => $this->lab, 'group_ids' => [$this->gOther],
        ])->assertStatus(422)->assertJsonValidationErrors('group_ids');

        // options: faol guruhlar tartib bo'yicha; nofaoli o'tkazib yuboriladi
        DB::table('laboratory_groups')->where('id', $this->g2)->update(['is_active' => false]);
        $opt = $this->actingAs($this->customer)->getJson('/api/portal/requests/options')->assertOk()->json();
        $this->assertSame([$this->g1], collect($opt['profiles'])->firstWhere('id', $id)['group_ids']);
        $this->assertTrue($opt['item_groups']);
    }

    /** SQL Server bir ustunni ORDER BY da ikki marta qabul qilmaydi (SQLite indamaydi) — so'rovlarni tekshiramiz */
    private function guardOrderBy(): void
    {
        DB::listen(function ($q) {
            if (preg_match('/order by (.+)$/i', $q->sql, $m)) {
                $cols = array_map(fn ($c) => strtolower(trim(preg_replace('/\\s+(asc|desc)\\s*$/i', '', trim($c)))), explode(',', $m[1]));
                $this->assertSame(count($cols), count(array_unique($cols)), 'ORDER BY takror: ' . $q->sql);
            }
        });
    }

    public function test_request_is_split_by_group_with_same_code(): void
    {
        $this->guardOrderBy();
        $pAu = $this->profile('AU', [$this->g1, $this->g3], $this->au);
        $pSs = $this->profile('SS', [$this->g2], $this->ss);

        $r = $this->actingAs($this->customer)->postJson('/api/portal/requests', [
            'department_id' => $this->dept->id, 'contact_name' => 'Rahimov A.', 'submit' => true,
            'items' => [
                ['customer_code' => '400', 'test_profile_id' => $pAu, 'element_ids' => [$this->au], 'svodka_point_id' => $this->point],
                ['customer_code' => '400', 'test_profile_id' => $pSs, 'element_ids' => [$this->ss], 'svodka_point_id' => $this->point],
                ['customer_code' => '401', 'test_profile_id' => $pAu, 'element_ids' => [$this->au], 'laboratory_group_id' => $this->g3],
            ],
            'svodka_date' => '2026-11-18', 'svodka_shift' => 2,
        ])->assertSuccessful()->json();

        $all = SampleRequest::with('items')->orderBy('id')->get();
        $this->assertCount(3, $all);
        $this->assertSame([$this->g1, $this->g2, $this->g3], $all->pluck('laboratory_group_id')->all());
        $this->assertSame(['400'], $all[0]->items->pluck('customer_code')->all());
        $this->assertSame(['400'], $all[1]->items->pluck('customer_code')->all());
        $this->assertSame(['401'], $all[2]->items->pluck('customer_code')->all());
        $this->assertSame([1], $all[1]->items->pluck('position')->all());
        $this->assertCount(1, $all->pluck('split_key')->unique());
        $this->assertNotNull($all[0]->split_key);
        $this->assertSame(['submitted'], $all->pluck('status')->unique()->values()->all());
        $this->assertCount(3, $all->pluck('number')->unique());
        $this->assertSame(['2026-11-18'], $all->map(fn ($x) => $x->svodka_date->toDateString())->unique()->values()->all());
        $this->assertSame([$this->g1], array_column($r['items'], 'laboratory_group_id'));
        $this->assertCount(2, $r['split']);

        // har guruh xodimi faqat o'z buyurtmasini ko'radi
        $op = User::factory()->create(['role_id' => Role::where('name', 'operator')->first()->id, 'laboratory_id' => $this->lab, 'laboratory_group_id' => $this->g2]);
        $list = $this->actingAs($op)->getJson('/api/sample-requests?tab=waiting')->json('data');
        $this->assertSame([$all[1]->id], array_column($list, 'id'));

        // qabul: guruh — namunaniki
        $item = $all[1]->items->first();
        $this->actingAs($this->head)->postJson("/api/sample-requests/{$all[1]->id}/receive", [
            'handed_over_by' => 'Rahimov', 'received_by' => $this->head->id, 'items' => [['id' => $item->id]],
        ])->assertOk();
        $s = Sample::sole();
        $this->assertSame([$this->g2, '400'], [(int) $s->assigned_group_id, $s->sample_number]);
    }

    public function test_one_group_no_split_and_missing_group_is_error(): void
    {
        $pAu = $this->profile('AU', [$this->g1], $this->au);
        $this->actingAs($this->customer)->postJson('/api/portal/requests', [
            'department_id' => $this->dept->id, 'submit' => true,
            'items' => [['customer_code' => '1', 'test_profile_id' => $pAu, 'element_ids' => [$this->au]], ['customer_code' => '2', 'element_ids' => [$this->au], 'laboratory_group_id' => $this->g1]],
        ])->assertSuccessful();
        $this->assertSame(1, SampleRequest::count());
        $this->assertNull(SampleRequest::first()->split_key);

        // profilsiz va guruhsiz — xato
        $this->actingAs($this->customer)->postJson('/api/portal/requests', [
            'department_id' => $this->dept->id, 'submit' => true, 'items' => [['customer_code' => '3', 'element_ids' => [$this->au]]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.laboratory_group_id');

        // eski usul: buyurtma guruhi — namunada guruh bo'lmasa ishlatiladi
        $this->actingAs($this->customer)->postJson('/api/portal/requests', [
            'department_id' => $this->dept->id, 'submit' => true, 'laboratory_group_id' => $this->g2, 'items' => [['customer_code' => '4', 'element_ids' => [$this->au]]],
        ])->assertSuccessful();
        $this->assertSame($this->g2, (int) SampleRequest::orderByDesc('id')->first()->laboratory_group_id);
    }

    public function test_schedule_split_and_manual_svodka_period(): void
    {
        $this->guardOrderBy();
        $pAu = $this->profile('AU', [$this->g1], $this->au);
        $pSs = $this->profile('SS', [$this->g2], $this->ss);
        $this->actingAs($this->head)->putJson("/api/request-settings/departments/{$this->dept->id}", ['enabled' => true, 'late_minutes' => 30, 'svodka_manual' => true])->assertOk()
            ->assertJsonPath('departments.0.svodka_manual', true);
        $this->assertTrue($this->actingAs($this->customer)->getJson('/api/portal/schedules')->json('enabled_departments.0.svodka_manual'));

        $this->actingAs($this->customer)->postJson('/api/portal/schedules', [
            'name' => 'Tong', 'time' => '07:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'payload' => [
                'sv_day' => -1, 'sv_shift' => 1,
                'items' => [
                    ['customer_code' => '400', 'test_profile_id' => $pAu, 'element_ids' => [$this->au], 'svodka_point_id' => $this->point],
                    ['customer_code' => '400', 'test_profile_id' => $pSs, 'element_ids' => [$this->ss], 'svodka_point_id' => $this->point],
                ],
            ],
        ])->assertCreated()->assertJsonPath('payload.sv_day', -1);

        $this->artisan('schedules:tick')->assertSuccessful(); // 06:00 — 60 daqiqa oldin
        $reqs = SampleRequest::orderBy('id')->get();
        $this->assertCount(2, $reqs);
        $run = ScheduleRun::whereDate('run_date', '2026-11-18')->sole();
        $this->assertSame([$run->id, $run->id], $reqs->pluck('schedule_run_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame((int) $reqs[0]->id, (int) $run->sample_request_id);
        $this->assertSame(['2026-11-17', 1], [$reqs[1]->svodka_date->toDateString(), (int) $reqs[1]->svodka_shift]);
        $this->assertSame(['400-07:00', '400-07:00'], $reqs->map(fn ($r) => $r->items()->value('customer_code'))->all());

        $pr = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs')->json('data.0');
        $this->assertCount(2, $pr['requests']);
        $this->assertSame(['400-07:00', '400-07:00'], $pr['codes']);

        // bittasi qabul qilinsa — partiya «qisman»; ikkinchisi ham — «qabul qilindi»
        foreach ($reqs as $i => $r) {
            $this->actingAs($this->head)->postJson("/api/sample-requests/{$r->id}/receive", [
                'handed_over_by' => 'X', 'received_by' => $this->head->id, 'items' => [['id' => $r->items()->value('id')]],
            ])->assertOk();
            $this->assertSame($i === 0 ? 'partial' : 'received', $run->fresh()->status);
        }

        // admin o'chirsa — avtomatik (grafik vaqtidan)
        $this->actingAs($this->head)->putJson("/api/request-settings/departments/{$this->dept->id}", ['enabled' => true, 'late_minutes' => 30, 'svodka_manual' => false])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00'));
        $this->artisan('schedules:tick')->assertSuccessful();
        $new = SampleRequest::whereDate('scheduled_at', '2026-11-19')->first();
        $this->assertNotSame(['2026-11-18', 1], [$new->svodka_date->toDateString(), (int) $new->svodka_shift]);
    }

    public function test_skip_cancels_all_split_requests(): void
    {
        $pAu = $this->profile('AU', [$this->g1], $this->au);
        $pSs = $this->profile('SS', [$this->g2], $this->ss);
        $this->actingAs($this->head)->putJson("/api/request-settings/departments/{$this->dept->id}", ['enabled' => true, 'late_minutes' => 30])->assertOk();
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'T', 'time' => '07:00', 'weekdays' => [3], 'payload' => ['items' => [
            ['customer_code' => '1', 'test_profile_id' => $pAu, 'element_ids' => [$this->au]],
            ['customer_code' => '2', 'test_profile_id' => $pSs, 'element_ids' => [$this->ss]],
        ]]])->assertCreated();
        $this->artisan('schedules:tick')->assertSuccessful();
        $run = ScheduleRun::whereDate('run_date', '2026-11-18')->sole();
        $reason = (int) DB::table('close_reasons')->orderBy('id')->value('id');
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run->id}/skip", ['reason_id' => $reason, 'note' => 'bugun yo\'q'])->assertOk();
        $this->assertSame(['cancelled'], SampleRequest::pluck('status')->unique()->values()->all());
        $this->assertSame('skipped', $run->fresh()->status);
    }
}
