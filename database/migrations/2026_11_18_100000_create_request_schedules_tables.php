<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 36] Grafik bo'yicha namunalar (buyurtmachi bo'linmasi).
 *   request_schedules — grafik qatori: vaqt, hafta kunlari, namunalar (shablon kabi payload)
 *   schedule_runs     — har kungi partiya: holati (planned|skipped|received|partial|missed), shu kun uchun
 *                       namunalar nusxasi (buyurtmachi o'sha kunga o'zgartira oladi), qabul ma'lumoti
 *   customer_departments.schedule_enabled / schedule_late_minutes — laboratoriya har bo'linmaga yoqadi
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_schedules')) {
            Schema::create('request_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->string('name', 120);
                $table->string('planned_time', 5);              // HH:MM
                $table->string('weekdays', 20)->default('1,2,3,4,5,6,7'); // ISO: 1 = dushanba … 7 = yakshanba
                $table->text('payload');                        // JSON: guruh, kim olib keladi, izoh, namunalar
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes(); // o'chirilgan qator nomi tarixda ko'rinib tursin
            });
        }

        if (! Schema::hasTable('schedule_runs')) {
            Schema::create('schedule_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('schedule_id')->index();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->date('run_date')->index();
                $table->string('planned_time', 5);
                $table->string('status', 12)->default('planned')->index();
                $table->text('payload');                        // JSON — shu kunning namunalari
                $table->boolean('customized')->default(false);  // buyurtmachi shu kunga o'zgartirgan
                $table->unsignedBigInteger('sample_request_id')->nullable()->index();
                $table->timestamp('arrived_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->unsignedInteger('received_count')->default(0);
                $table->unsignedInteger('missing_count')->default(0);
                $table->string('note', 500)->nullable();
                $table->timestamp('late_notified_at')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['schedule_id', 'run_date'], 'schedule_runs_schedule_date_unique');
            });
        }

        if (Schema::hasTable('customer_departments')) {
            Schema::table('customer_departments', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_departments', 'schedule_enabled')) {
                    $table->boolean('schedule_enabled')->default(false);
                }
                if (! Schema::hasColumn('customer_departments', 'schedule_late_minutes')) {
                    $table->unsignedSmallInteger('schedule_late_minutes')->default(30);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_runs');
        Schema::dropIfExists('request_schedules');
        if (Schema::hasTable('customer_departments')) {
            Schema::table('customer_departments', function (Blueprint $table) {
                foreach (['schedule_enabled', 'schedule_late_minutes'] as $c) {
                    if (Schema::hasColumn('customer_departments', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
