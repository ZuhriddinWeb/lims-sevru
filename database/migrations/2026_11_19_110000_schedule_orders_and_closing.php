<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 39] Grafik — oddiy buyurtma; kechiktirib kiritish; sabab bilan yopish.
 *
 *   sample_requests.scheduled_at         — grafik vaqti (grafik buyurtmasida; ro'yxatda «Grafik · 08:00»)
 *   sample_requests.late_entry           — namuna kechiktirib kiritilgan (haqiqiy qabul vaqti oldinroq)
 *   sample_requests.received_entered_at  — qabul dasturga qachon kiritilgan (received_at — haqiqiy vaqt)
 *   sample_requests.close_reason_id      — yopish sababi (close_reasons)
 *   sample_requests.auto_closed          — muddati o'tib, sababsiz avtomatik yopilgan
 *   close_reasons                        — yopish sabablari ro'yxati (admin / laboratoriya boshlig'i tuzadi)
 *   customer_departments.schedule_*      — bo'linmaga xos: buyurtma necha daqiqa oldin yaratiladi, muddat qoidasi
 *   schedule_runs.reason_id              — «Bugun yo'q» sababi
 *
 * «Grafik bo'yicha qabul» sahifasi olib tashlandi: menyu va schedules.receive ruxsati o'chiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('close_reasons')) {
            Schema::create('close_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('name_uz', 191);
                $table->string('name_ru', 191)->nullable();
                $table->string('name_en', 191)->nullable();
                $table->boolean('needs_note')->default(false); // «Boshqa» — izoh majburiy
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('sample_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('sample_requests', 'scheduled_at')) {
                $table->dateTime('scheduled_at')->nullable()->index();
            }
            if (! Schema::hasColumn('sample_requests', 'late_entry')) {
                $table->boolean('late_entry')->default(false);
            }
            if (! Schema::hasColumn('sample_requests', 'received_entered_at')) {
                $table->dateTime('received_entered_at')->nullable();
            }
            if (! Schema::hasColumn('sample_requests', 'close_reason_id')) {
                $table->unsignedBigInteger('close_reason_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sample_requests', 'auto_closed')) {
                $table->boolean('auto_closed')->default(false);
            }
        });

        if (Schema::hasTable('customer_departments')) {
            Schema::table('customer_departments', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_departments', 'schedule_lead_minutes')) {
                    $table->unsignedSmallInteger('schedule_lead_minutes')->nullable();   // null — umumiy sozlama
                }
                if (! Schema::hasColumn('customer_departments', 'schedule_expire_mode')) {
                    $table->string('schedule_expire_mode', 12)->nullable();               // day_end|shift_end|next_run|hours
                }
                if (! Schema::hasColumn('customer_departments', 'schedule_expire_hours')) {
                    $table->unsignedSmallInteger('schedule_expire_hours')->nullable();
                }
            });
        }

        if (Schema::hasTable('schedule_runs') && ! Schema::hasColumn('schedule_runs', 'reason_id')) {
            Schema::table('schedule_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('reason_id')->nullable();
            });
        }

        // «Grafik bo'yicha qabul» — endi oddiy «Buyurtmalar» sahifasida
        if (Schema::hasTable('menu_items')) {
            DB::table('menu_items')->where('key', 'lab_schedule')->delete();
        }
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->where('name', 'schedules.receive')->pluck('id')->all();
            if ($ids) {
                foreach (['permission_role', 'permission_user'] as $pivot) {
                    if (Schema::hasTable($pivot)) {
                        DB::table($pivot)->whereIn('permission_id', $ids)->delete();
                    }
                }
                DB::table('permissions')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // qaytarilmaydi (serverlarda rollback ishlatilmaydi)
    }
};
