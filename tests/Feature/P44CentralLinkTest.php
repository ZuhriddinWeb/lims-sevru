<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Central\CentralLink;
use Database\Seeders\CentralLinkSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** [Paket 44] Markaziy portalga uzatish: faqat admin, kalit maxfiy, faqat ruxsat berilgan bloklar, imzo */
class P44CentralLinkTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ngmk1.3.AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';

    private User $admin;
    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, CentralLinkSeeder::class]);
        $this->admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->head = User::factory()->create(['role_id' => Role::where('name', 'lab_head')->first()->id]);
    }

    private function configure(array $extra = []): void
    {
        $this->actingAs($this->admin)->putJson('/api/central-link', array_merge([
            'url' => 'http://192.168.14.23:5000', 'key' => self::KEY, 'enabled' => true,
            'datasets' => ['summary', 'flow'], 'interval' => 5,
        ], $extra))->assertOk();
    }

    public function test_only_admin_and_key_is_never_returned(): void
    {
        $this->actingAs($this->head)->getJson('/api/central-link')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/central-link')->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('key_set', false)
            ->assertJsonPath('defaults', ['summary', 'trend', 'flow', 'shifts', 'equipment']);

        $this->actingAs($this->admin)->putJson('/api/central-link', ['key' => 'parol123'])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->actingAs($this->admin)->putJson('/api/central-link', ['enabled' => true, 'url' => 'http://x.uz'])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->actingAs($this->admin)->putJson('/api/central-link', ['url' => 'ftp://x'])->assertStatus(422)->assertJsonValidationErrors('url');

        $this->configure();
        $res = $this->actingAs($this->admin)->getJson('/api/central-link')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('key_set', true)->assertJsonPath('key_lab', 3)
            ->assertJsonPath('datasets', ['summary', 'flow']);
        $this->assertStringNotContainsString(substr(self::KEY, 10), $res->getContent());
        // bazada ham ochiq holda turmaydi
        $this->assertStringNotContainsString(substr(self::KEY, 10), (string) \App\Models\AppSetting::where('key', CentralLink::KEY)->value('value'));

        // kalitni o'chirish — yuborish ham o'chadi
        $this->actingAs($this->admin)->putJson('/api/central-link', ['clear_key' => true])->assertOk()
            ->assertJsonPath('key_set', false)->assertJsonPath('enabled', false);
    }

    public function test_snapshot_contains_only_allowed_blocks_and_no_sample_codes(): void
    {
        $snap = app(CentralLink::class)->snapshot(['summary', 'flow']);
        $this->assertSame(['summary', 'flow'], $snap['datasets']);
        $this->assertArrayHasKey('today', $snap['summary']);
        $this->assertArrayHasKey('month', $snap['summary']);
        $this->assertSame(['stages', 'open', 'overdue_count'], array_keys($snap['flow']));
        foreach (['trend', 'customers', 'groups', 'equipment', 'requests', 'worksheets', 'shifts'] as $k) {
            $this->assertArrayNotHasKey($k, $snap);
        }

        $all = app(CentralLink::class)->snapshot(array_keys(CentralLink::DATASETS));
        $this->assertCount(30, $all['trend']['points']);
        $this->assertArrayHasKey('waiting', $all['requests']);
        $this->assertArrayNotHasKey('items', $all['worksheets']['awaiting'] ?? []);

        $none = app(CentralLink::class)->snapshot([]);
        $this->assertSame([], $none['datasets']); // faqat «onlayn» belgisi
        $this->assertArrayNotHasKey('summary', $none);

        $this->actingAs($this->admin)->getJson('/api/central-link/preview?datasets=trend')->assertOk()
            ->assertJsonPath('payload.datasets', ['trend']);
    }

    public function test_push_is_signed_and_status_recorded(): void
    {
        $this->configure();
        Http::fake(['192.168.14.23:5000/api/ingest' => Http::response(['ok' => true, 'received_at' => now()->toIso8601String()])]);

        $this->actingAs($this->admin)->postJson('/api/central-link/push')->assertOk()
            ->assertJsonPath('status.ok', true)->assertJsonPath('status.http', 200);

        Http::assertSent(function (HttpRequest $r) {
            $ts = $r->header('X-Ngmk-Timestamp')[0];
            $body = $r->body();

            return $r->url() === 'http://192.168.14.23:5000/api/ingest'
                && $r->header('X-Ngmk-Lab')[0] === '3'
                && hash_equals(hash_hmac('sha256', $ts . '.' . $body, self::KEY), $r->header('X-Ngmk-Signature')[0])
                && json_decode($body, true)['datasets'] === ['summary', 'flow']
                && ! str_contains($body, self::KEY);
        });
        $st = app(CentralLink::class)->status();
        $this->assertTrue($st['ok']);
        $this->assertNotNull($st['last_ok_at']);
    }

    public function test_failure_and_scheduler_interval(): void
    {
        $this->configure(['interval' => 10]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response(['ok' => false, 'message' => 'Imzo noto\'g\'ri'], 401) : Http::response(['ok' => true]);
        });

        $this->artisan('central:push')->assertFailed();
        $st = app(CentralLink::class)->status();
        $this->assertFalse($st['ok']);
        $this->assertSame(401, $st['http']);
        $this->assertSame(1, $st['fails']);
        $this->assertStringContainsString('Imzo', $st['error']);

        // oraliq o'tmagan — jim (yangi so'rov yo'q)
        $this->artisan('central:push')->assertSuccessful();
        $this->assertSame(1, $calls);

        $this->travel(11)->minutes();
        $this->artisan('central:push')->assertSuccessful();
        $this->assertSame(2, $calls);
        $this->assertSame(0, app(CentralLink::class)->status()['fails']);

        // o'chiq — hech narsa
        $this->actingAs($this->admin)->putJson('/api/central-link', ['enabled' => false])->assertOk();
        $this->travel(30)->minutes();
        $this->artisan('central:push')->assertSuccessful();
        $this->assertSame(2, $calls);
    }

    public function test_menu_and_permission_seeded(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => 'central.manage']);
        $this->assertDatabaseHas('menu_items', ['key' => 'central_link', 'route' => 'central-link']);
        $this->seed(CentralLinkSeeder::class); // qayta — xavfsiz
        $this->assertSame(1, \App\Models\MenuItem::where('key', 'central_link')->count());
    }
}
