<?php

namespace Tests\Feature;

use App\Models\CloseReason;
use App\Models\CustomerDepartment;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Sample;
use App\Models\SampleRequest;
use App\Models\ScheduleRun;
use App\Models\User;
use App\Services\Samples\RequestSchedules;
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

/** [Paket 36 + 39] Grafik — oddiy buyurtma: avtomatik yaratish, muddat, kechiktirib kiritish, sabab bilan yopish */
class P39ScheduleOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $head;
    private User $operator;
    private User $viewer;
    private User $customer;
    private User $customer2;
    private CustomerDepartment $dept;
    private CustomerDepartment $dept2;
    private int $au;
    private int $point;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 06:00')); // chorshanba
        $this->seed([PermissionSeeder::class, RoleSeeder::class, SampleIntakeModuleSeeder::class,
            SampleRequestModuleSeeder::class, SvodkaModuleSeeder::class, ScheduleModuleSeeder::class]);
        $lab = DB::table('laboratories')->insertGetId(['code' => 'TL', 'name_ru' => 'ЦЗЛ', 'name_uz' => 'MZL', 'name_en' => 'CL', 'created_at' => now(), 'updated_at' => now()]);
        $role = fn ($n) => Role::where('name', $n)->first()->id;
        $this->head = User::factory()->create(['name' => 'Karimova N.', 'role_id' => $role('lab_head'), 'laboratory_id' => $lab]);
        $this->operator = User::factory()->create(['name' => 'Aliyev Q.', 'role_id' => $role('operator'), 'laboratory_id' => $lab]);
        $this->viewer = User::factory()->create(['name' => 'Rahbar', 'role_id' => $role('viewer'), 'laboratory_id' => $lab]);
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

    private function at(string $t): void
    {
        Carbon::setTestNow(Carbon::parse($t));
    }

    private function payload(int $n = 3, array $codes = []): array
    {
        return ['contact_name' => 'Rahimov Akmal', 'items' => array_map(fn ($i) => [
            'customer_code' => $codes[$i - 1] ?? 'Xvost', 'state_of_matter' => 'solid', 'element_ids' => [$this->au],
            'svodka_point_id' => $i === 1 ? $this->point : null, 'quantity' => 100, 'quantity_unit' => 'g',
        ], range(1, $n))];
    }

    private function enable(CustomerDepartment $d, array $extra = []): void
    {
        $this->actingAs($this->head)->putJson("/api/request-settings/departments/{$d->id}", ['enabled' => true, 'late_minutes' => 30] + $extra)->assertOk();
    }

    private function makeSchedule(string $time = '08:00', array $days = [1, 2, 3, 4, 5, 6, 7], ?array $payload = null): array
    {
        return $this->actingAs($this->customer)->postJson('/api/portal/schedules', [
            'name' => 'Ertalabki', 'time' => $time, 'weekdays' => $days, 'payload' => $payload ?? $this->payload(3, ['Xvost', '147', '']),
        ])->assertCreated()->json();
    }

    private function tick(): void
    {
        $this->artisan('schedules:tick')->assertSuccessful();
    }

    private function reason(string $like): int
    {
        return (int) CloseReason::where('name_uz', 'like', "%$like%")->value('id');
    }

    public function test_settings_permissions_and_isolation(): void
    {
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'X', 'time' => '08:00', 'weekdays' => [1], 'payload' => $this->payload()])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->assertNotContains('portal-schedule', array_column($this->actingAs($this->customer)->getJson('/api/menu')->json(), 'route'));
        $this->actingAs($this->customer)->putJson("/api/request-settings/departments/{$this->dept->id}", ['enabled' => true])->assertForbidden();
        $this->actingAs($this->operator)->getJson('/api/request-settings')->assertForbidden();
        $this->actingAs($this->operator)->getJson('/api/request-control')->assertForbidden();
        $this->actingAs($this->viewer)->getJson('/api/request-control')->assertOk();

        $set = $this->actingAs($this->head)->getJson('/api/request-settings')->assertOk()->json();
        $this->assertSame(60, $set['settings']['schedule_lead_minutes']);
        $this->assertSame(72, $set['settings']['late_entry_hours']);
        $this->assertGreaterThanOrEqual(5, count($set['reasons']));
        $this->actingAs($this->head)->putJson('/api/request-settings', ['late_entry_hours' => 0])->assertStatus(422);
        $this->actingAs($this->head)->putJson('/api/request-settings', ['schedule_expire_mode' => 'shift_end', 'auto_close_days' => 5])->assertOk()
            ->assertJsonPath('settings.schedule_expire_mode', 'shift_end')->assertJsonPath('settings.auto_close_days', 5);

        $this->enable($this->dept);
        $this->assertContains('portal-schedule', array_column($this->actingAs($this->customer)->getJson('/api/menu')->json(), 'route'));
        $this->assertNotContains('lab-schedule', array_column($this->actingAs($this->head)->getJson('/api/menu')->json(), 'route'));
        $s = $this->makeSchedule('8.00');
        $this->assertSame('08:00', $s['time']);
        $this->assertCount(0, $this->actingAs($this->customer2)->getJson('/api/portal/schedules')->json('data'));
        $this->actingAs($this->customer2)->putJson("/api/portal/schedules/{$s['id']}", ['name' => 'Z', 'time' => '09:00', 'weekdays' => [1], 'payload' => $this->payload()])->assertNotFound();
        $this->getJson('/api/schedule-board')->assertNotFound(); // eski sahifa yo'q
    }

    public function test_auto_order_codes_and_ordinary_receive(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00');
        $this->makeSchedule('10:00', [1, 2]); // chorshanba emas

        $this->at('2026-11-18 06:55');
        $this->tick();
        $this->assertSame(0, SampleRequest::count());
        $run = $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs')->json('data.0');
        $this->assertSame(['Xvost-08:00', '147-08:00', '08:00'], $run['codes']);
        $this->assertSame('2026-11-18 07:00', $run['order_at']);

        $this->at('2026-11-18 07:00');
        $this->tick();
        $req = SampleRequest::sole();
        $this->assertSame(['schedule', 'submitted', '2026-11-18 08:00'], [$req->origin, $req->status, $req->scheduled_at->format('Y-m-d H:i')]);
        $this->assertSame(['Xvost-08:00', '147-08:00', '08:00'], $req->items->pluck('customer_code')->all());
        $this->assertSame('2026-11-18 23:59', $req->expires_at->format('Y-m-d H:i'));
        $today = fn () => ScheduleRun::whereDate('run_date', '2026-11-18')->where('planned_time', '08:00')->sole();
        $this->assertSame('ordered', $today()->status);
        $this->tick(); // takror yaratilmaydi
        $this->assertSame(1, SampleRequest::count());
        $this->assertSame(0, Notification::where('user_id', $this->head->id)->where('title', 'like', 'Yangi buyurtma%')->count());

        // buyurtma yaratilgach shu kunga o'zgartirib bo'lmaydi
        $this->actingAs($this->customer)->putJson("/api/portal/schedule-runs/{$run['id']}", ['payload' => $this->payload(1)])->assertStatus(422);
        $this->actingAs($this->customer)->postJson("/api/portal/requests/{$req->id}/withdraw")->assertStatus(422);

        // oddiy ro'yxatlarda (laboratoriya — nuqta nomisiz), manba filtri
        $list = $this->actingAs($this->operator)->getJson('/api/sample-requests?tab=waiting')->assertOk();
        $this->assertSame($req->id, $list->json('data.0.id'));
        $this->assertSame('2026-11-18 08:00', $list->json('data.0.scheduled_at'));
        $this->assertCount(0, $this->actingAs($this->operator)->getJson('/api/sample-requests?tab=waiting&source=request')->json('data'));
        $this->assertCount(1, $this->actingAs($this->customer)->getJson('/api/portal/requests?source=schedule')->json('data'));
        $this->assertStringNotContainsString('Maxfiy', $this->actingAs($this->operator)->getJson("/api/sample-requests/{$req->id}")->getContent());

        // qisman qabul — oddiy forma
        $this->at('2026-11-18 08:12');
        $items = $req->items->sortBy('position')->values();
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", [
            'handed_over_by' => 'Rahimov Akmal', 'received_by' => $this->head->id,
            'items' => [['id' => $items[0]->id], ['id' => $items[1]->id]],
        ])->assertOk();
        $s = Sample::orderBy('id')->get();
        $this->assertSame(["{$req->number}-1", "{$req->number}-2"], $s->pluck('sample_number')->all()); // proba raqami — yagona
        $this->assertSame(['Xvost-08:00', '147-08:00'], $s->pluck('customer_code')->all());
        $this->assertSame(['partial', 2], [$today()->status, (int) $today()->received_count]);
        $req->refresh();
        $this->assertSame(['2026-11-18', 2], [$req->svodka_date->toDateString(), (int) $req->svodka_shift]);
        $this->assertFalse($req->late_entry);

        // qolganini rad etish — ro'yxatdan sabab
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/reject", ['reason_id' => $this->reason("to'xtagan")])->assertOk();
        $this->assertSame(['partial', 1], [$today()->status, (int) $today()->missing_count]);
        $this->assertStringContainsString("to'xtagan", SampleRequest::sole()->close_reason);
    }

    public function test_expire_modes(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00');
        $this->makeSchedule('10:00');
        $svc = app(RequestSchedules::class);
        $svc->ensureRuns(today());
        $run = ScheduleRun::where('planned_time', '08:00')->sole();
        $m = fn ($mode, $h = 4) => $svc->expiresAt($run, ['schedule_expire_mode' => $mode, 'schedule_expire_hours' => $h, 'schedule_lead_minutes' => 60] + \App\Services\Samples\RequestSettings::DEFAULTS)->format('Y-m-d H:i');
        $this->assertSame('2026-11-18 23:59', $m('day_end'));
        $this->assertSame('2026-11-18 12:00', $m('hours'));
        $this->assertSame('2026-11-18 10:00', $m('next_run'));
        $this->assertSame('2026-11-18 23:59', $m('shift_end')); // smena yo'q — kun oxiri
        DB::table('lab_shifts')->insert([
            ['name' => 'Kunduzgi', 'start_time' => '08:00', 'end_time' => '20:00', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tungi', 'start_time' => '20:00', 'end_time' => '08:00', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame('2026-11-18 20:00', $m('shift_end'));

        // bo'linma sozlamasi umumiydan ustun
        $this->enable($this->dept, ['expire_mode' => 'hours', 'expire_hours' => 2, 'lead_minutes' => 30]);
        $this->at('2026-11-18 07:29');
        $this->tick();
        $this->assertSame(0, SampleRequest::count());
        $this->at('2026-11-18 07:30');
        $this->tick();
        $this->assertSame('2026-11-18 10:00', SampleRequest::sole()->expires_at->format('Y-m-d H:i'));
        $this->at('2026-11-18 10:05');
        $this->tick();
        $this->assertSame('expired', SampleRequest::find($run->fresh()->sample_request_id)->status);
        $this->assertSame('missed', $run->fresh()->status);
    }

    public function test_late_entry_rules(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00', [3]); // faqat chorshanba
        $this->at('2026-11-18 07:00');
        $this->tick();
        $req = SampleRequest::sole();
        $item = fn () => ['items' => [['id' => $req->items()->where('status', 'pending')->value('id')]]];

        $this->at('2026-11-19 10:00');
        $this->tick();
        $this->assertSame('expired', $req->fresh()->status);

        // kelajak va buyurtmadan oldin — xato
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", $item() + ['received_at' => '2026-11-19 12:00'])->assertStatus(422)->assertJsonValidationErrors('received_at');
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", $item() + ['received_at' => '2026-11-17 08:00'])->assertStatus(422)->assertJsonValidationErrors('received_at');

        // 72 soat ichida — oddiy xodim kiritadi; probalar haqiqiy vaqt bilan
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", $item() + ['received_at' => '2026-11-18 08:10'])->assertOk()
            ->assertJsonPath('request.late_entry', true);
        $req->refresh();
        $this->assertSame(['2026-11-18 08:10', '2026-11-19 10:00'], [$req->received_at->format('Y-m-d H:i'), $req->received_entered_at->format('Y-m-d H:i')]);
        $this->assertSame('2026-11-18 08:10', Sample::sole()->registered_at->format('Y-m-d H:i'));
        $this->assertSame('partial', $req->status);

        // 72 soatdan eski — faqat laboratoriya boshlig'i
        $this->at('2026-11-21 12:00');
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", $item() + ['received_at' => '2026-11-18 08:15'])->assertStatus(422);
        $this->actingAs($this->head)->postJson("/api/sample-requests/{$req->id}/receive", $item() + ['received_at' => '2026-11-18 08:15'])->assertOk();

        // nazorat hisoboti
        $c = $this->actingAs($this->head)->getJson('/api/request-control?from=2026-11-01&to=2026-11-30')->assertOk()->json();
        $this->assertSame([1, 1, 1, 0], [$c['totals']['total'], $c['totals']['schedule'], $c['totals']['late_entry'], $c['totals']['on_time']]);
        $this->assertSame($req->number, $c['late'][0]['number']);
        $this->actingAs($this->head)->get('/api/request-control?from=2026-11-01&to=2026-11-30&export=1')->assertOk();
    }

    public function test_close_with_reason_skip_auto_close_and_remind(): void
    {
        $this->enable($this->dept);
        $s = $this->makeSchedule('08:00');
        $this->makeSchedule('14:00');
        $run8 = fn () => ScheduleRun::where('planned_time', '08:00')->whereDate('run_date', '2026-11-18')->first();
        $run14 = fn () => ScheduleRun::where('planned_time', '14:00')->whereDate('run_date', '2026-11-18')->first();
        $this->tick();

        // buyurtma yaratilmasidan oldin «Bugun yo'q» — sabab majburiy; «Boshqa» — izoh bilan
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run14()->id}/skip", [])->assertStatus(422)->assertJsonValidationErrors('reason_id');
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run14()->id}/skip", ['reason_id' => $this->reason('izoh bilan')])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run14()->id}/skip", ['reason_id' => $this->reason('Uskuna')])->assertOk()->assertJsonPath('status', 'skipped');
        $this->assertTrue(Notification::where('user_id', $this->head->id)->where('title', 'like', "%bugun yo'q%")->exists());
        $this->at('2026-11-18 13:30');
        $this->tick();
        $this->assertSame(1, SampleRequest::count()); // 14:00 uchun buyurtma yaratilmadi

        // buyurtma yaratilgandan keyin «Bugun yo'q» — buyurtma sabab bilan yopiladi
        $req8 = SampleRequest::sole();
        $this->actingAs($this->customer)->postJson("/api/portal/schedule-runs/{$run8()->id}/skip", ['reason_id' => $this->reason('izoh bilan'), 'note' => '2-sex ta\'mirda'])->assertOk();
        $req8->refresh();
        $this->assertSame(['cancelled', 'skipped'], [$req8->status, $run8()->status]);
        $this->assertSame("Boshqa (izoh bilan): 2-sex ta'mirda", $req8->close_reason);

        // ertangi: muddati o'tdi → yopilmagan; sababsiz yopib bo'lmaydi
        $this->at('2026-11-19 07:00');
        $this->tick();
        $this->at('2026-11-20 01:00');
        $this->tick();
        $r19 = SampleRequest::whereDate('scheduled_at', '2026-11-19')->where('scheduled_at', 'like', '%08:00%')->first();
        $this->assertSame('expired', $r19->status);
        $this->actingAs($this->customer)->postJson("/api/portal/requests/{$r19->id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason_id');

        // kunlik eslatma
        $this->artisan('sample-requests:expire --remind')->assertSuccessful();
        $this->assertTrue(Notification::where('user_id', $this->customer->id)->where('title', 'like', 'Yopilmagan buyurtmalar%')->exists());
        $this->assertTrue(Notification::where('user_id', $this->head->id)->where('title', 'like', 'Yopilmagan buyurtmalar%')->exists());

        // 7 kundan keyin — avtomatik yopiladi (sababsiz), grafik partiyasi «kelmadi»
        $this->at('2026-11-27 02:00');
        $this->artisan('sample-requests:expire')->assertSuccessful();
        $r19->refresh();
        $this->assertSame(['cancelled', true], [$r19->status, $r19->auto_closed]);
        $this->assertSame('missed', ScheduleRun::where('sample_request_id', $r19->id)->value('status'));
        $c = $this->actingAs($this->head)->getJson('/api/request-control?from=2026-11-18&to=2026-11-19')->json();
        $this->assertSame(1, $c['totals']['closed']);
        $this->assertGreaterThanOrEqual(1, $c['totals']['auto_closed']);
        $this->assertContains('auto', array_column($c['reasons'], 'key'));

        // ishlatilgan sabab o'chirilmaydi — nofaol bo'ladi
        $this->actingAs($this->head)->deleteJson('/api/request-settings/reasons/' . $this->reason('izoh bilan'))->assertOk()->assertJsonPath('deactivated', true);
        $new = $this->actingAs($this->head)->postJson('/api/request-settings/reasons', ['name_uz' => 'Yangi sabab'])->assertCreated()->json('id');
        $this->actingAs($this->head)->deleteJson("/api/request-settings/reasons/$new")->assertOk()->assertJsonPath('deactivated', false);
    }

    public function test_source_filter_and_combined_export(): void
    {
        $this->enable($this->dept);
        $this->makeSchedule('08:00');
        $this->at('2026-11-18 07:00');
        $this->tick();
        $req = SampleRequest::sole();
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req->id}/receive", ['items' => $req->items->map(fn ($i) => ['id' => $i->id])->all()])->assertOk();
        $runId = ScheduleRun::where('sample_request_id', $req->id)->value('id');
        $this->assertSame($runId, $this->actingAs($this->customer)->getJson('/api/portal/schedule-runs?date=2026-11-18')->json('data.0.id'));

        $r = $this->actingAs($this->customer)->postJson('/api/portal/requests', ['department_id' => $this->dept->id, 'submit' => true,
            'items' => [['customer_code' => 'B-1', 'state_of_matter' => 'solid', 'element_ids' => [$this->au]]]])->assertSuccessful()->json();
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$r['id']}/receive", ['items' => [['id' => $r['items'][0]['id']]]])->assertOk();
        $this->assertSame('B-1', Sample::where('customer_code', 'B-1')->value('sample_number')); // oddiy buyurtmada — avvalgidek

        $count = fn ($q) => $this->actingAs($this->customer)->getJson('/api/portal/samples?status=all&' . $q)->assertOk()->json('total');
        $this->assertSame(4, $count('source=all'));
        $this->assertSame(3, $count('source=schedule'));
        $this->assertSame(1, $count('source=request'));
        $this->assertSame(3, $count("run_id=$runId"));

        $x = $this->actingAs($this->customer)->get('/api/portal/samples/export?status=all&source=all')->assertOk();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($x->baseResponse->getFile()->getPathname()) === true);
        $all = ($zip->getFromName('xl/sharedStrings.xml') ?: '') . ($zip->getFromName('xl/worksheets/sheet1.xml') ?: '');
        $this->assertStringContainsString('Grafik 08:00', $all);
    }

    public function test_group_restriction(): void
    {
        $lab = DB::table('laboratories')->value('id');
        foreach (['G1', 'G2'] as $c) {
            DB::table('laboratory_groups')->insert(['laboratory_id' => $lab, 'code' => $c, 'name_uz' => $c, 'name_ru' => $c, 'name_en' => $c, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $groups = DB::table('laboratory_groups')->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $this->enable($this->dept);
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'A', 'time' => '08:00', 'weekdays' => [3], 'payload' => $this->payload() + ['laboratory_group_id' => $groups[0]]])->assertCreated();
        $this->actingAs($this->customer)->postJson('/api/portal/schedules', ['name' => 'B', 'time' => '09:00', 'weekdays' => [3], 'payload' => $this->payload()])
            ->assertStatus(422)->assertJsonValidationErrors('payload.laboratory_group_id');
        $this->at('2026-11-18 07:00');
        $this->tick();
        $this->operator->update(['laboratory_group_id' => $groups[1]]);
        $this->assertCount(0, $this->actingAs($this->operator)->getJson('/api/sample-requests?tab=waiting')->json('data'));
        $this->assertCount(1, $this->actingAs($this->head)->getJson('/api/sample-requests?tab=waiting')->json('data'));
    }
}
