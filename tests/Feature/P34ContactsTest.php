<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\CustomerDepartment;
use App\Models\Role;
use App\Models\User;
use App\Services\Samples\CustomerContacts;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SampleIntakeModuleSeeder;
use Database\Seeders\SampleRequestModuleSeeder;
use Database\Seeders\SvodkaModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** [Paket 34] Bo'linma xodimlari ma'lumotnomasi: «Kim olib keladi» + telefon */
class P34ContactsTest extends TestCase
{
    use RefreshDatabase;

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
        DB::table('laboratory_groups')->update(['is_active' => false]);
    }

    private function send(string $name, ?string $phone, ?CustomerDepartment $d = null, ?User $as = null, bool $submit = true): array
    {
        $r = $this->actingAs($as ?? $this->customer)->postJson('/api/portal/requests', [
            'department_id' => ($d ?? $this->dept)->id, 'contact_name' => $name, 'contact_phone' => $phone,
            'items' => [['customer_code' => '908', 'state_of_matter' => 'solid', 'element_ids' => [$this->element]]],
            'submit' => $submit,
        ]);
        $r->assertSuccessful();

        return $r->json();
    }

    public function test_normalization(): void
    {
        $this->assertSame('Rahimov Akmal', CustomerContacts::cleanName("  rahimov   AKMAL "));
        $this->assertSame('A. A. Rahimov', CustomerContacts::cleanName('A.A.Rahimov'));
        $this->assertSame("O'rinov Botir o'g'li", CustomerContacts::cleanName("o ' rinov  botir O`G`LI"));
        $this->assertSame(CustomerContacts::nameKey('Рахимов Акмал'), CustomerContacts::nameKey('Raximov  akmal'));

        $this->assertSame('+998 90 123-45-67', CustomerContacts::phone('901234567')['display']);
        $this->assertSame('+998 90 123-45-67', CustomerContacts::phone('+998 (90) 123 45 67')['display']);
        $this->assertSame('+998 90 123-45-67', CustomerContacts::phone('8 90 123 4567')['display']);
        $this->assertSame(['display' => '2-34-56', 'digits' => '23456', 'kind' => 'internal'], CustomerContacts::phone('2-34-56'));
        $this->assertSame('other', CustomerContacts::phone('+7 912 345 67 89')['kind']);

        $sim = fn ($a, $b) => CustomerContacts::similarity(CustomerContacts::tokens($a), CustomerContacts::tokens($b));
        $this->assertSame('initials', $sim('Rahimov A.', 'Rahimov Akmal'));
        $this->assertSame('initials', $sim('A. Rahimov', 'Rahimov Akmal'));
        $this->assertSame('order', $sim('Akmal Rahimov', 'Rahimov Akmal'));
        $this->assertSame('spelling', $sim('Rahimow Akmal', 'Rahimov Akmal'));
        $this->assertSame('spelling', $sim('Rahimov Akmal', 'Рахимов Акмал'));
        $this->assertNull($sim('Karimov Akmal', 'Rahimov Akmal'));
        $this->assertNull($sim('Rahimov Botir', 'Rahimov Akmal'));
    }

    public function test_submit_remembers_contact_and_updates_phone(): void
    {
        $req = $this->send('  rahimov   akmal ', '90 111 22 33');
        $this->assertSame('Rahimov Akmal', $req['contact_name']);      // buyurtmada ham tozalangan
        $this->assertSame('+998 90 111-22-33', $req['contact_phone']);
        $c = CustomerContact::where('customer_department_id', $this->dept->id)->sole();
        $this->assertSame(['Rahimov Akmal', '+998 90 111-22-33', 1], [$c->full_name, $c->phone, $c->use_count]);

        // qoralama — ma'lumotnomaga yozilmaydi
        $this->send('Yangi Odam', '901234567', submit: false);
        $this->assertSame(1, CustomerContact::count());

        // xuddi shu odam, telefon o'zgardi — avtomatik yangilanadi
        $this->send('RAHIMOV AKMAL', '+998 91 555 44 33');
        $c->refresh();
        $this->assertSame(['+998 91 555-44-33', 2], [$c->phone, $c->use_count]);
        $this->assertDatabaseHas('activity_logs', ['model_type' => 'CustomerContact', 'model_id' => $c->id]);

        // telefon yozilmasa — eskisi qoladi
        $this->send('Rahimov Akmal', '');
        $this->assertSame('+998 91 555-44-33', $c->fresh()->phone);

        // ichki raqam ham saqlanadi
        $this->send('Tursunov B.', '2-34-56');
        $this->assertSame('2-34-56', CustomerContact::where('full_name', 'Tursunov B.')->value('phone'));
    }

    public function test_list_and_check_are_department_scoped(): void
    {
        $this->send('Rahimov Akmal', '901112233');
        $this->send('Karimov Botir', '4523', $this->dept2, $this->customer2);

        $list = $this->actingAs($this->customer)->getJson('/api/portal/contacts')->assertOk()->json('data');
        $this->assertSame(['Rahimov Akmal'], array_column($list, 'name'));
        $this->actingAs($this->customer)->getJson('/api/portal/contacts?department_id=' . $this->dept2->id)->assertStatus(422);

        $chk = fn ($name) => $this->actingAs($this->customer)->postJson('/api/portal/contacts/check', ['name' => $name, 'phone' => '91 222 33 44'])->assertOk()->json();
        $r = $chk('  rahimov akmal');
        $this->assertSame('Rahimov Akmal', $r['exact']['name']);
        $this->assertTrue($r['same_format']);
        $this->assertSame(['value' => '+998 91 222-33-44', 'kind' => 'mobile'], $r['phone']);

        $r = $chk('Рахимов Акмал');                 // boshqa yozuv — o'xshash (so'raladi)
        $this->assertNull($r['exact']);
        $this->assertSame('Rahimov Akmal', $r['similar'][0]['name']);
        $this->assertSame('Rahimov Akmal', $chk('Rahimov A.')['similar'][0]['name']);
        $this->assertSame([], $chk('Karimov Botir')['similar']);   // boshqa bo'linmaniki ko'rinmaydi

        // laboratoriya xodimi — istalgan bo'linma (faqat o'qish)
        $lab = $this->actingAs($this->operator)->getJson('/api/portal/contacts?department_id=' . $this->dept2->id)->assertOk()->json();
        $this->assertSame(['Karimov Botir'], array_column($lab['data'], 'name'));
        $this->assertFalse($lab['can_write']);
        $id = CustomerContact::where('full_name', 'Karimov Botir')->value('id');
        $this->actingAs($this->operator)->deleteJson("/api/portal/contacts/$id")->assertForbidden();
    }

    public function test_edit_and_delete_by_department_only(): void
    {
        $this->send('Rahimov Akmal', '901112233');
        $this->send('Rahimov A.', '901112233');
        $ids = CustomerContact::orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->actingAs($this->customer2)->putJson("/api/portal/contacts/{$ids[1]}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->customer)->putJson("/api/portal/contacts/{$ids[1]}", ['name' => 'rahimov  akmal'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($this->customer)->putJson("/api/portal/contacts/{$ids[0]}", ['name' => 'Rahimov Akmal Karimovich', 'phone' => '93 000 11 22'])
            ->assertOk()->assertJsonPath('phone', '+998 93 000-11-22');
        $this->actingAs($this->customer)->deleteJson("/api/portal/contacts/{$ids[1]}")->assertOk();
        $this->assertSame(1, CustomerContact::count());
    }

    public function test_lab_receive_adds_courier_to_department(): void
    {
        $req = $this->send('Rahimov Akmal', '901112233');
        $items = collect($req['items'])->map(fn ($i) => ['id' => $i['id'], 'customer_code' => '908', 'element_ids' => [$this->element], 'state_of_matter' => 'solid'])->all();
        $this->actingAs($this->operator)->postJson("/api/sample-requests/{$req['id']}/receive", [
            'items' => $items, 'handed_over_by' => ' tursunov  bobur ', 'handed_over_phone' => '2-34-56',
        ])->assertOk()->assertJsonPath('request.handed_over_by', 'Tursunov Bobur')->assertJsonPath('request.handed_over_phone', '2-34-56');
        $this->assertSame('2-34-56', CustomerContact::where('customer_department_id', $this->dept->id)->where('full_name', 'Tursunov Bobur')->value('phone'));
    }

    public function test_collect_command(): void
    {
        $this->send('Rahimov Akmal', '901112233');
        $this->send('RAHIMOV  akmal', '91 222 33 44');
        $this->send('Рахимов Акмал', null);
        $this->send('Rahimov A.', null);
        CustomerContact::query()->delete();

        $this->artisan('lims:kontaktlar')->assertSuccessful();
        $this->assertSame(0, CustomerContact::count());
        // o'xshash juftliklar so'raladi: kirill yozuvi — «ha» (birlashadi), «Rahimov A.» — «yo'q» (alohida)
        $this->artisan('lims:kontaktlar --yoz')
            ->expectsConfirmation("Bitta odammi? «Rahimov Akmal» (2) ↔ «Рахимов Акмал» (1) — ha bo'lsa «Rahimov Akmal» qoladi", 'yes')
            ->expectsConfirmation("Bitta odammi? «Rahimov Akmal» (3) ↔ «Rahimov A.» (1) — ha bo'lsa «Rahimov Akmal» qoladi", 'no')
            ->assertSuccessful();
        $c = CustomerContact::where('name_key', CustomerContacts::nameKey('Rahimov Akmal'))->sole();
        $this->assertSame(['+998 91 222-33-44', 3], [$c->phone, $c->use_count]);
        $this->assertSame(2, CustomerContact::count());
        $this->artisan('lims:kontaktlar --yoz --no-interaction')->assertSuccessful();
        $this->assertSame(3, CustomerContact::count());  // so'ralmasa — o'xshashi alohida qo'shiladi, bori takrorlanmaydi
        $this->artisan('lims:kontaktlar --yoz --no-interaction')->assertSuccessful();
        $this->assertSame(3, CustomerContact::count());
    }
}
