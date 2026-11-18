<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** lims:demo-malumot — yangi bazada har modulga ma'lumot qo'yadi; ma'lumotli bazada to'xtaydi */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_fills_tables_and_refuses_second_run(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->seed();
        $this->artisan('lims:upgrade')->assertSuccessful();
        $this->artisan('lims:demo-malumot')->assertSuccessful();

        foreach (['sample_requests', 'samples', 'sample_elements', 'worksheets', 'worksheet_rows', 'worksheet_signoffs', 'worksheet_scans',
            'svodka_releases', 'svodka_assignments', 'portal_request_templates', 'customer_contacts', 'sample_quantity_movements',
            'equipment', 'equipment_calibrations', 'equipment_usages', 'test_objects', 'water_orders', 'water_order_results',
            'interlab_peers', 'interlab_referrals', 'interlab_results', 'help_feedback', 'help_unanswered', 'notifications', 'lab_shifts'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->count(), "$t bo'sh");
        }
        $n = DB::table('samples')->count();
        $this->artisan('lims:demo-malumot')->assertFailed();
        $this->assertSame($n, DB::table('samples')->count());
    }
}
