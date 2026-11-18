<?php

namespace Tests\Feature;

use App\Models\CustomerDepartment;
use App\Models\Notification;
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

/** [Paket 36] Grafik bo'yicha namunalar */
class P36ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $head;
    private User $operator;
    private User $customer;
    private User $customer2;
    private CustomerDepartment $dept;
    private CustomerDepartment $dept2;
    private int $au;
    private int $point;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 07:30')); // chorshanba
        $this->seed([PermissionSeeder::class, RoleSeeder::class, SampleIntakeModuleSeeder::class,
            SampleRequestModuleSeeder::class, SvodkaModuleSeeder::class, ScheduleModuleSeeder::class]);
        $lab = DB::table('laboratories')->insertGetId(['code' => 'TL', 'name_ru' => 'ЦЗЛ', 'name_uz' => 'MZL', 'name_en' => 'CL', 'created_at' => now(), 'updated_at' => now()]);
        $role = fn ($n) => Role::where('name', $n)->first()->id;
        $this->head = User::factory()->create(['name' => 'Karimova N.', 'role_id' => $role('lab_head'), 'laboratory_id' => $lab]);
        $this->operator = User::factory()->create(['name' => 'Aliyev Q.', 'role_id' => $role('operator'), 'laboratory_id' => $lab]);
        $this->customer = User::factory()->create(['name' => 'OTK vakil', 'role_id' => $role('customer')]);
        $this->customer2 = User::factory()->create(['name' => 'TTG vakil', 'role_id' => $role('customer')]);
        $this->dept = CustomerDepartment::create(['code' => 'OTK', 'name' => 'OTK GMZ-3', 'is_active' => true]);
        $this->dept2 = CustomerDepartment::create(['code' => 'TTG', 'name' => 'TTG', 'is_active' => true]);
        DB::table('customer_department_user')->insert([
            ['customer_department_id' => $this->dept->id, 'user_id' => $this->customer->id],
            ['customer_department_id' => $this->dept2->id, 'user_id' => $this->customer2->id],
        ]);
        $this->au = DB::table('elements')->insertGetId(['symbol' => 'Au', 'name_ru' => 'Золото', 'name_uz' => 'Oltin', 'name_en' => 'Gold', 'default_unit' => 'g/t', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('laboratory_groups')->update(['is_active' => false]);
        $sec = DB::table('svodka_sections')->insertGetId(['customer_department_id' => $this->dept->id, 'title' => 'AU', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->point = DB::table('svodka_points')->insertGetId(['customer_department_id' => $this->dept->id, 'section_id' => $sec, 'name' => 'Maxfiy nuqta SAF', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(int $n = 3): array
    {
        return ['contact_name' => 'Rahimov Akmal', 'items' => array_map(fn ($i) => [
            'customer_code' => (string) (900 + $i), 'state_of_matter' => 'solid', 'element_ids' => [$this->au],
            'svodka_point_id' => $i === 1 ? $this->point : null, 'quantity' => 100, 'quantity_unit' => 'g',
        ], range(1, $n))];
    }

    private function enable(CustomerDepartment $d, bool $on = true): void
    {
        $this->actingAs($this->head)->putJson("/api/schedule-board/settings/{$d->id}", ['enabled' => $on, 'late_minutes' => 30])->assertOk();
    }

    private function makeSchedule(string $time = '08:00', array $days = [1, 2, 3, 4, 5, 6, 7]): array
    {
        return $this->actingAs($this->customer)->postJson('/api/portal/schedules', [
            'name' => 'Ertalabki', 'time' => $time, 'weekdays' => $days, 'payload' => $this->payload(),
        ])->assertCreated()->json();
    }

    public function test_optional_per_department_and_department_isolation(): void
    {
        // yoqilmagan — yozib bo'lmaydi, menyuda yo'q
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'X', 'time' => '08:00', 'weekdays' => [1], 'payload' => $this->payload()])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->assertNotContains('portal-schedule', array_column($this->actingAs($this->customer)->getJson('/api/menu')->json(), 'route'));
        // buyurtmachi o'zi yoqa olmaydi
        $this->actingAs($this->customer)->putJson("/api/schedule-board/settings/{$this->dept->id}", ['enabled' => true])->assertForbidden();

        $this->enable($this->dept);
        $this->assertContains('portal-schedule', array_column($this->actingAs($this->customer)->getJson('/api/menu')->json(), 'route'));
        $s = $this->makeSchedule('8.00');
        $this->assertSame('08:00', $s['time']);
        $this->assertSame(3, $s['items_count']);
        // vaqt xato
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'Y', 'time' => '25:00', 'weekdays' => [1], 'payload' => $this->payload()])
            ->assertStatus(422)->assertJsonValidationErrors('time');
        // boshqa bo'linma ko'rmaydi, o'zgartira olmaydi
        $this->assertCount(0, $this->actingAs($this->customer2)->getJson('/api/portal/schedules')->json('data'));
        $this->actingAs($this->customer2)->putJson("/api/portal/schedules/{$s['id']}", ['name' => 'Z', 'time' => '09:00', 'weekdays' => [1], 'payload' => $this->payload()])->assertNotFound();
        $this->assertCount(0, $this->actingAs($this->customer2)->getJson('/api/portal/schedule-runs')->json('data'));
    }

    public function test_daily_run_arrival_partial_and_notifications(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00');
        $this->makeSchedule('10:00', [1, 2]); // chorshanba emas — bugun partiya yo'q

        $runs = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs')->assertOk()->json('data');
        $this->assertCount(1, $runs);
        $this->assertSame('planned', $runs[0]['status']);
        $this->assertSame('Maxfiy nuqta SAF', $runs[0]['items'][0]['svodka_point']);

        // laboratoriya: nuqta nomi yo'q
        $board = $this->actingAs($this->operator)->getJson('/api/schedule-board')->assertOk();
        $this->assertCount(1, $board->json('data'));
        $this->assertStringNotContainsString('Maxfiy', $board->getContent());

        Carbon::setTestNow(Carbon::parse('2026-11-18 08:12'));
        $run = $this->actingAs($this->operator)->postJson("/api/schedule-board/{$runs[0]['id']}/arrive", [
            'positions' => [1, 2], 'handed_over_by' => 'Rahimov Akmal', 'received_by' => $this->head->id,
        ])->assertOk()->json();
        $this->assertSame(['partial', 2, 1, 'Karimova N.'], [$run['status'], $run['received_count'], $run['missing_count'], $run['received_by']]);
        // qayta bosib bo'lmaydi
        $this->actingAs($this->operator)->postJson("/api/schedule-board/{$runs[0]['id']}/arrive", [])->assertStatus(422);

        $req = SampleRequest::where('origin', 'schedule')->sole();
        $this->assertSame('received', $req->status);
        $this->assertSame(2, Sample::count());
        $this->assertSame('2026-11-18', $req->svodka_date->toDateString());
        $this->assertSame(2, (int) $req->svodka_shift); // 08:00 — kunduzgi (II) navbatchilik

        // alohida: oddiy ro'yxatlarda yo'q
        $this->assertCount(0, $this->actingAs($this->customer)->getJson('/api/portal/requests')->json('data'));
        $this->assertCount(0, $this->actingAs($this->head)->getJson('/api/sample-requests?tab=received')->json('data'));

        // buyurtmachiga xabar; «yangi buyurtma» xabari laboratoriyaga bormaydi
        $n = Notification::where('user_id', $this->customer->id)->latest('id')->first();
        $this->assertStringContainsString('2 ta namunangiz qabul qilindi', $n->title);
        $this->assertStringContainsString('1 tasi kelmadi', $n->body);
        $this->assertSame(0, Notification::where('user_id', $this->head->id)->where('title', 'like', 'Yangi buyurtma%')->count());
    }

    public function test_source_filter_and_combined_export(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00');
        $runId = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs')->json('data.0.id');
        $this->actingAs($this->operator)->postJson("/api/schedule-board/{$runId}/arrive", [])->assertOk();

        // oddiy buyurtma
        $r = $this->actingAs($this->customer)->postJson('/api/portal/requests', ['department_id' => $this->dept->id, 'submit' => true,
            'items' => [['customer_code' => 'B-1', 'state_of_matter' => 'solid', 'element_ids' => [$this->au]]]])->assertSuccessful()->json();
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$r['id']}/receive", ['items' => [['id' => $r['items'][0]['id']]]])->assertOk();

        $count = fn ($q) => $this->actingAs($this->customer)->getJson('/api/portal/samples?status=all&' . $q)->assertOk()->json('total');
        $this->assertSame(4, $count('source=all'));
        $this->assertSame(3, $count('source=schedule'));
        $this->assertSame(1, $count('source=request'));
        $this->assertSame(3, $count("run_id=$runId"));
        $this->assertSame([$this->dept->id], $this->actingAs($this->customer)->getJson('/api/portal/context')->json('schedule_departments'));

        $x = $this->actingAs($this->customer)->get('/api/portal/samples/export?status=all&source=all')->assertOk();
        $file = $x->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $strings = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
        $this->assertStringContainsString('Manba', $strings . $sheet);
        $this->assertStringContainsString('Grafik 08:00', $strings . $sheet);
    }

    public function test_customer_day_changes_and_tick(): void
    {
        $this->enable($this->dept);
        $s = $this->makeSchedule('08:00');
        $run = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs')->json('data.0');

        // shu kunga o'zgartirish: 1 ta namuna
        $this->actingAs($this->customer)->putJson("/api/portal/schedule-runs/{$run['id']}", ['payload' => $this->payload(1)])
            ->assertOk()->assertJsonPath('items_count', 1)->assertJsonPath('customized', true);
        // grafik o'zgarsa ham shu kungi o'zgarish saqlanadi
        $this->actingAs($this->customer)->putJson("/api/portal/schedules/{$s['id']}", ['name' => 'Ertalabki', 'time' => '08:30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'payload' => $this->payload(5)])->assertOk();
        $this->assertSame([1, '08:30'], [count(ScheduleRun::find($run['id'])->payload['items']), ScheduleRun::find($run['id'])->planned_time]);
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run['id']}/reset")->assertOk()->assertJsonPath('items_count', 5);

        // bugun yo'q → laboratoriyaga xabar; qaytarish
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run['id']}/skip", ['note' => "Sex to'xtagan"])->assertOk()->assertJsonPath('status', 'skipped');
        $this->assertTrue(Notification::where('user_id', $this->head->id)->where('title', 'like', "%bugun yo'q%")->exists());
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run['id']}/unskip")->assertOk()->assertJsonPath('status', 'planned');

        // kechikish: 08:30 + 30 daqiqa
        Carbon::setTestNow(Carbon::parse('2026-11-18 08:45'));
        $this->artisan('schedules:tick')->assertSuccessful();
        $this->assertNull(ScheduleRun::find($run['id'])->late_notified_at);
        Carbon::setTestNow(Carbon::parse('2026-11-18 09:05'));
        $this->assertSame('late', $this->actingAs($this->customer)->getJson("/api/portal/schedule-runs/{$run['id']}")->json('status'));
        $this->artisan('schedules:tick')->assertSuccessful();
        $this->assertTrue(Notification::where('user_id', $this->customer->id)->where('title', 'like', '%hali kelmadi%')->exists());
        $this->assertTrue(Notification::where('user_id', $this->operator->id)->where('title', 'like', '%namunalar kelmadi%')->exists());
        $this->artisan('schedules:tick')->assertSuccessful(); // takror xabar yo'q
        $this->assertSame(1, Notification::where('user_id', $this->customer->id)->where('title', 'like', '%hali kelmadi%')->count());

        // ertasi kun: kechagisi «kelmadi», bugungisi tayyorlanadi
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00'));
        $this->artisan('schedules:tick')->assertSuccessful();
        $this->assertSame('missed', ScheduleRun::find($run['id'])->status);
        $this->assertSame(1, ScheduleRun::whereDate('run_date', '2026-11-19')->count());
        $hist = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs/history?from=2026-11-18&to=2026-11-19')->assertOk()->json('data');
        $this->assertSame(['2026-11-19', '2026-11-18'], array_column($hist, 'date'));

        // o'chirilgan grafik: kelmagan kelgusi partiyalar o'chadi, tarix qoladi
        $this->actingAs($this->customer)->deleteJson("/api/portal/schedules/{$s['id']}")->assertOk();
        $this->assertSame(0, ScheduleRun::whereDate('run_date', '2026-11-19')->count());
        $this->assertSame('Ertalabki', $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs/history?from=2026-11-18&to=2026-11-18')->json('data.0.runs.0.name'));
    }

    public function test_group_restriction_on_board(): void
    {
        $lab = DB::table('laboratories')->value('id');
        foreach (['G1', 'G2'] as $c) {
            DB::table('laboratory_groups')->insert(['laboratory_id' => $lab, 'code' => $c, 'name_uz' => $c, 'name_ru' => $c, 'name_en' => $c, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $groups = DB::table('laboratory_groups')->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $this->enable($this->dept);
        $p = $this->payload() + ['laboratory_group_id' => $groups[0]];
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'A', 'time' => '08:00', 'weekdays' => [3], 'payload' => $p])->assertCreated();
        // guruhsiz — majburiy
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'B', 'time' => '09:00', 'weekdays' => [3], 'payload' => $this->payload()])
            ->assertStatus(422)->assertJsonValidationErrors('payload.laboratory_group_id');

        $this->operator->update(['laboratory_group_id' => $groups[1]]);
        $this->assertCount(0, $this->actingAs($this->operator)->getJson('/api/schedule-board')->json('data'));
        $runId = ScheduleRun::value('id');
        $this->actingAs($this->operator)->postJson("/api/schedule-board/{$runId}/arrive", [])->assertNotFound();
        $this->assertCount(1, $this->actingAs($this->head)->getJson('/api/schedule-board')->json('data'));
    }
}
