<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 37] Grafik jadvallari (Excel ko'rinishidagi kunlik jadval).
 *   schedule_sheets       — admin tuzgan jadval shabloni: bo'lim, grafik qatorlari, ustunlar (JSON)
 *   schedule_sheet_values — laboratoriya kiritgan qiymatlar: jadval × partiya (schedule_run) × ustun
 * Formula va o'rtacha ustunlar saqlanmaydi — har safar hisoblanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_sheets')) {
            Schema::create('schedule_sheets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->string('name', 150);
                $table->text('schedule_ids')->nullable();   // JSON: qaysi grafik qatorlari (bo'sh — bo'limning hammasi)
                $table->text('columns');                    // JSON: ustunlar
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('schedule_sheet_values')) {
            Schema::create('schedule_sheet_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sheet_id')->index();
                $table->unsignedBigInteger('run_id')->index();
                $table->string('column_key', 40);
                $table->decimal('value', 20, 6)->nullable();
                $table->unsignedBigInteger('entered_by')->nullable();
                $table->timestamp('entered_at')->nullable();
                $table->timestamps();
                $table->unique(['sheet_id', 'run_id', 'column_key'], 'schedule_sheet_values_cell_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_sheet_values');
        Schema::dropIfExists('schedule_sheets');
    }
};
