<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 38] Paket 37 («Grafik jadvallari») bekor qilindi — uning izlarini tozalash.
 * P37 o'rnatilmagan bazada (serverlar) hech narsa qilmaydi.
 * Kelajakdagi «Hisobot shakllari» moduli alohida, grafikdan mustaqil quriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('schedule_sheet_values');
        Schema::dropIfExists('schedule_sheets');

        if (Schema::hasTable('menu_items')) {
            DB::table('menu_items')->whereIn('key', ['schedule_sheets', 'schedule_sheet_templates', 'portal_schedule_sheets'])->delete();
        }
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', ['schedule_sheets.view', 'schedule_sheets.enter', 'schedule_sheets.manage'])->pluck('id')->all();
            if ($ids) {
                if (Schema::hasTable('permission_role')) {
                    DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
                }
                if (Schema::hasTable('permission_user')) {
                    DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
                }
                DB::table('permissions')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // qaytarilmaydi
    }
};
