<?php

namespace Tests\Feature;

use App\Models\CustomerDepartment;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sample;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SampleIntakeModuleSeeder;
use Database\Seeders\SampleRequestModuleSeeder;
use Database\Seeders\SvodkaModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Paket 33] Shifr takrori, qabulda «kim olib keldi / kim qabul qilib oldi», buyurtmachi shablonlari,
 * «Svodka» → «Jamlanma hisobot» (o'zbekcha).
 */
class P33RequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $head;
    private User $operator;
    private User $customer;
    private User $customer2;
    private CustomerDepartment $dept;
    private CustomerDepartment $dept2;
    private int $element;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, SampleIntakeModuleSeeder::class,
            SampleRequestModuleSeeder::class, SvodkaModuleSeeder::class]);

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
        $this->element = DB::table('elements')->insertGetId(['symbol' => 'Au', 'name_ru' => 'Золото', 'name_uz' => 'Oltin', 'name_en' => 'Gold', 'default_unit' => 'g/t', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('laboratory_groups')->update(['is_active' => false]); // guruh tanlash majburiy bo'lmasin
    }

    private function sendRequest(User $u, array $codes): array
    {
        $items = array_map(fn ($c) => ['customer_code' => $c, 'state_of_matter' => 'solid', 'element_ids' => [$this->element]], $codes);
        $r = $this->actingAs($u)->postJson('/api/portal/requests', [
            'department_id' => $this->dept->id, 'contact_name' => 'Rahimov A.', 'contact_phone' => '+998 90 111 22 33',
            'items' => $items, 'submit' => true,
        ]);
        $r->assertSuccessful();

        return $r->json();
    }

    private function receive(array $req, array $extra = [], ?User $as = null)
    {
        $items = collect($req['items'])->map(fn ($i) => ['id' => $i['id'], 'customer_code' => $i['customer_code'], 'element_ids' => [$this->element], 'state_of_matter' => 'solid'])->all();

        return $this->actingAs($as ?? $this->operator)->postJson("/api/sample-requests/{$req['id']}/receive", ['items' => $items] + $extra);
    }

    public function test_same_code_inside_one_request_is_allowed(): void
    {
        $req = $this->sendRequest($this->customer, ['908', '908', '910']);
        $this->assertSame('submitted', $req['status']);
        $this->receive($req)->assertOk();
        $this->assertSame(2, Sample::where('customer_code', '908')->count());
    }

    public function test_same_code_repeats_in_same_month(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->receive($this->sendRequest($this->customer, ['908']))->assertOk();
        }
        $this->assertSame(3, Sample::where('customer_code', '908')->where('period_month', now()->format('Y-m'))->count());
        // qo'lda ro'yxatga olish ham (SampleIntakeHook validatsiyasi)
        $rules = \App\Services\Samples\SampleIntake::rules();
        $v = validator(['customer_department_id' => $this->dept->id, 'customer_code' => '908'], $rules);
        $this->assertFalse($v->fails());
    }

    public function test_receive_stores_courier_phone_and_chosen_receiver(): void
    {
        $opts = $this->actingAs($this->operator)->getJson('/api/sample-requests/options')->assertOk()->json();
        $ids = array_column($opts['receivers'], 'id');
        $this->assertContains($this->head->id, $ids);
        $this->assertContains($this->operator->id, $ids);
        $this->assertNotContains($this->customer->id, $ids, 'buyurtmachi ro\'yxatda bo\'lmasin');
        $this->assertSame($this->operator->id, $opts['me']);

        $req = $this->sendRequest($this->customer, ['A1']);
        $r = $this->receive($req, ['handed_over_by' => 'Rahimov A.', 'handed_over_phone' => '+998 90 999 00 11', 'received_by' => $this->head->id])->assertOk();
        $this->assertSame('Karimova N.', $r->json('request.received_by'));
        $this->assertSame('+998 90 999 00 11', $r->json('request.handed_over_phone'));
        $s = Sample::where('customer_code', 'A1')->first();
        $this->assertSame($this->head->id, (int) $s->received_by);
        $this->assertSame($this->operator->id, (int) $s->registered_by);
        $intake = $this->actingAs($this->head)->getJson("/api/samples/{$s->id}/intake")->assertOk()->json('intake');
        $this->assertSame('Karimova N.', $intake['received_by']);
        $this->assertSame('Rahimov A.', $intake['handed_over_by']);
    }

    public function test_receive_defaults_and_rejects_foreign_receiver(): void
    {
        $req = $this->sendRequest($this->customer, ['B1']);
        $this->receive($req, ['received_by' => $this->customer->id])->assertStatus(422)->assertJsonValidationErrors('received_by');
        $r = $this->receive($req)->assertOk();   // received_by yo'q — joriy xodim; telefon — buyurtmadagi
        $this->assertSame('+998 90 111 22 33', $r->json('request.handed_over_phone'));
        $this->assertSame($this->operator->id, (int) Sample::where('customer_code', 'B1')->value('received_by'));
    }

    public function test_templates_belong_to_department_only(): void
    {
        $payload = ['contact_name' => 'Rahimov A.', 'items' => [
            ['customer_code' => '908', 'state_of_matter' => 'solid', 'element_ids' => [$this->element], 'quantity' => 200, 'quantity_unit' => 'g'],
            ['customer_code' => '909', 'state_of_matter' => 'liquid', 'element_ids' => [$this->element]],
        ]];
        $r = $this->actingAs($this->customer)->postJson('/api/portal/request-templates', ['name' => 'Kunduzgi', 'payload' => $payload])->assertCreated();
        $id = $r->json('id');
        $this->assertSame($this->dept->id, $r->json('department_id'));
        $this->assertSame(2, $r->json('items_count'));

        // nom bo'lim ichida takrorlanmaydi
        $this->actingAs($this->customer)->postJson('/api/portal/request-templates', ['name' => 'Kunduzgi', 'payload' => $payload])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        // boshqa bo'linma ko'rmaydi va o'zgartira olmaydi
        $this->assertCount(0, $this->actingAs($this->customer2)->getJson('/api/portal/request-templates')->assertOk()->json('data'));
        $this->actingAs($this->customer2)->putJson("/api/portal/request-templates/$id", ['name' => 'X', 'payload' => $payload])->assertNotFound();
        $this->actingAs($this->customer2)->deleteJson("/api/portal/request-templates/$id")->assertNotFound();
        // boshqa bo'linma nomidan yaratib bo'lmaydi
        $this->actingAs($this->customer2)->postJson('/api/portal/request-templates', ['name' => 'Y', 'department_id' => $this->dept->id, 'payload' => $payload])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        // o'zinikini ko'radi, tahrirlaydi, tartiblaydi, o'chiradi
        $this->assertCount(1, $this->actingAs($this->customer)->getJson('/api/portal/request-templates')->json('data'));
        $this->actingAs($this->customer)->putJson("/api/portal/request-templates/$id", ['name' => 'Kunduzgi smena', 'payload' => $payload])
            ->assertOk()->assertJsonPath('name', 'Kunduzgi smena');
        $id2 = $this->actingAs($this->customer)->postJson('/api/portal/request-templates', ['name' => 'Tungi', 'payload' => $payload])->json('id');
        $this->actingAs($this->customer)->postJson('/api/portal/request-templates/order', ['ids' => [$id2, $id]])->assertOk();
        $this->assertSame([$id2, $id], array_column($this->actingAs($this->customer)->getJson('/api/portal/request-templates')->json('data'), 'id'));
        $this->actingAs($this->customer)->deleteJson("/api/portal/request-templates/$id")->assertOk();
        $this->assertDatabaseMissing('portal_request_templates', ['id' => $id]);
        // bo'sh shablon — xato
        $this->actingAs($this->customer)->postJson('/api/portal/request-templates', ['name' => 'Bo\'sh', 'payload' => ['items' => []]])
            ->assertStatus(422);
        // laboratoriya xodimi portal ko'rinishida faqat o'qiydi (portal.request yo'q)
        $this->actingAs($this->operator)->postJson('/api/portal/request-templates', ['name' => 'Z', 'payload' => $payload])->assertForbidden();
    }

    public function test_menu_and_uzbek_rename(): void
    {
        $this->assertSame('Shablonlarim', MenuItem::where('key', 'portal_templates')->value('title_uz'));
        $this->assertSame('Jamlanma hisobot', MenuItem::where('key', 'portal_svodka')->value('title_uz'));
        $this->assertSame('Jamlanma hisobotlar', MenuItem::where('key', 'svodka_releases')->value('title_uz'));
        $this->assertSame('Сводка', MenuItem::where('key', 'portal_svodka')->value('title_ru'));

        // eski server: eski nomlar → seeder qayta ishga tushganda almashadi; admin qo'ygan nom saqlanadi
        MenuItem::where('key', 'portal_svodka')->update(['title_uz' => 'Svodka']);
        MenuItem::where('key', 'svodka_releases')->update(['title_uz' => 'Kunlik hisobotlar']);
        Permission::where('name', 'svodka.view')->update(['label_uz' => "Buyurtmachi svodkasi — Ko'rish (buyurtmachidek, nuqta nomlari bilan)"]);
        $this->seed(SvodkaModuleSeeder::class);
        $this->assertSame('Jamlanma hisobot', MenuItem::where('key', 'portal_svodka')->value('title_uz'));
        $this->assertSame('Kunlik hisobotlar', MenuItem::where('key', 'svodka_releases')->value('title_uz'));
        $this->assertStringStartsWith('Buyurtmachi jamlanma hisoboti — ', Permission::where('name', 'svodka.view')->value('label_uz'));
        $this->assertSame(1, MenuItem::where('key', 'portal_templates')->count());

        // portal foydalanuvchisi menyusida «Shablonlarim» bor
        $menu = $this->actingAs($this->customer)->getJson('/api/menu')->assertOk()->json();
        $this->assertContains('portal-templates', array_column($menu, 'route'));
    }
}
