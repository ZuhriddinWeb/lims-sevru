<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monitoring nuqtalari (suv manbalari / havo joylari) — AI tahlili uchun
        Schema::create('monitoring_points', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['water', 'air', 'radiation'])->default('water');
            $table->string('location')->nullable();      // Joylashuv tavsifi
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('laboratory_group_id')->nullable()->constrained('laboratory_groups')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Suv/havo laboratoriyasi buyurtmalari
        Schema::create('water_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('source_document')->nullable(); // Hujjat raqami / joylashuv
            $table->string('division')->nullable();         // Podrazdelenie

            // Qaysi guruhga taqsimlangan (Водные ресурсы / Воздух / Радиационный контроль)
            $table->unsignedBigInteger('assigned_group_id')->nullable()->index();
            $table->foreignId('monitoring_point_id')->nullable()->constrained('monitoring_points')->nullOnDelete();

            $table->enum('status', [
                'registered',
                'in_progress',
                'completed',
                'archived'
            ])->default('registered');

            $table->string('period_month', 7)->index(); // YYYY-MM

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at')->nullable(); // avtomatik
            $table->timestamp('completed_at')->nullable();   // avtomatik
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // Suv/havo natijalari (vaqt bo'yicha — AI trend tahlili uchun)
        Schema::create('water_order_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('water_order_id')->constrained('water_orders')->cascadeOnDelete();
            $table->string('parameter');                 // pH, loyqalik, element...
            $table->decimal('value', 20, 6)->nullable();
            $table->string('unit')->nullable();
            // Me'yor chegaralari — "normada" yoki "yomonlashish" aniqlash uchun
            $table->decimal('norm_min', 20, 6)->nullable();
            $table->decimal('norm_max', 20, 6)->nullable();
            $table->enum('assessment', ['normal', 'warning', 'critical', 'unknown'])->default('unknown');
            $table->timestamp('measured_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parameter', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('water_order_results');
        Schema::dropIfExists('water_orders');
        Schema::dropIfExists('monitoring_points');
    }
};
