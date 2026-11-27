<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\Worksheets\PrintStamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** [Paket 45] Chop etishdagi «Yaratdi / Tasdiqladi» bloki va QR: sozlama (admin), varaq javobidagi kodlar */
class P45PrintStampsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed();
        $this->artisan('lims:upgrade')->assertSuccessful();
        $this->artisan('lims:demo-malumot')->assertSuccessful();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->first()->id]);
    }

    public function test_settings_defaults_and_only_manager_can_change(): void
    {
        $admin = $this->user('admin');
        $analyst = $this->user('analyst');

        $this->actingAs($analyst)->getJson('/api/worksheet-print-settings')->assertOk()
            ->assertJsonPath('settings', ['sign_block' => true, 'qr' => true, 'qr_mode' => 'text']);
        $this->actingAs($analyst)->putJson('/api/worksheet-print-settings', ['qr' => false])->assertForbidden();

        $this->actingAs($admin)->putJson('/api/worksheet-print-settings', ['qr_mode' => 'sms'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/worksheet-print-settings', ['qr' => false, 'qr_mode' => 'link'])->assertOk()
            ->assertJsonPath('settings', ['sign_block' => true, 'qr' => false, 'qr_mode' => 'link']);
        $this->actingAs($admin)->putJson('/api/worksheet-print-settings', ['sign_block' => false])->assertOk()
            ->assertJsonPath('settings.qr_mode', 'link')->assertJsonPath('settings.sign_block', false);
    }

    public function test_worksheet_payload_has_stamps_with_stable_codes(): void
    {
        $admin = $this->user('admin');
        $w = Worksheet::whereNotNull('approved_at')->whereNotNull('approved_by')->with(['creator', 'approver'])->firstOrFail();

        $res = $this->actingAs($admin)->getJson("/api/worksheets/{$w->id}")->assertOk();
        $p = $res->json('print_stamps');
        $this->assertSame(['sign_block' => true, 'qr' => true, 'qr_mode' => 'text'], $p['settings']);
        $this->assertSame($w->creator->name, $p['created']['name']);
        $this->assertSame($w->approver->name, $p['approved']['name']);
        $this->assertSame($w->approved_at->format('Y-m-d H:i'), $p['approved']['at']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $p['approved']['code']);
        $this->assertSame(PrintStamps::code($w->id, 'approved', (int) $w->approved_by, $p['approved']['at']), $p['approved']['code']);
        $this->assertNotSame($p['created']['code'], $p['approved']['code']);
        // qayta so'rovda kod o'zgarmaydi
        $this->assertSame($p['approved']['code'], $this->actingAs($admin)->getJson("/api/worksheets/{$w->id}")->json('print_stamps.approved.code'));

        $open = Worksheet::whereNull('approved_at')->firstOrFail();
        $this->assertNull($this->actingAs($admin)->getJson("/api/worksheets/{$open->id}")->assertOk()->json('print_stamps.approved'));
    }
}
