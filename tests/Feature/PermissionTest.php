<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    public function test_admin_has_every_permission(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);

        $this->assertTrue($admin->hasPermission('samples.delete'));
        $this->assertTrue($admin->hasPermission('roles.create'));
    }

    public function test_analyst_can_enter_results_but_not_delete(): void
    {
        $analyst = User::factory()->create(['role_id' => Role::where('name', 'analyst')->first()->id]);

        $this->assertTrue($analyst->hasPermission('samples.result'));
        $this->assertFalse($analyst->hasPermission('samples.delete'));
        $this->assertFalse($analyst->hasPermission('users.view'));
    }

    public function test_guest_cannot_reach_protected_endpoint(): void
    {
        $this->getJson('/api/samples')->assertStatus(401);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::where('name', 'viewer')->first()->id]);

        $this->actingAs($viewer)->postJson('/api/samples', [])->assertStatus(403);
    }
}
