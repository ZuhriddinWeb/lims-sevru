<?php

use App\Support\DbCompat;
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
            DbCompat::foreignId($table, 'laboratory_group_id', on: 'laboratory_groups', safeForSqlServer: true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Suv/havo laboratoriyasi buyurtmalari
        Schema::create('water_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('source_document')->nullable(); // Hujjat raqami / joylashuv
            $table->string('division')->nullable();         // Podrazdelenie

            // Qaysi guruhga taqsimlangan — SQL Server uchun indeks, MySQL uchun FK
            DbCompat::foreignId($table, 'assigned_group_id', on: 'laboratory_groups');
            DbCompat::foreignId($table, 'monitoring_point_id', on: 'monitoring_points', safeForSqlServer: true);

            $table->enum('status', [
                'registered',
                'in_progress',
                'completed',
                'archived'
            ])->default('registered');

            $table->string('period_month', 7)->index(); // YYYY-MM

            DbCompat::foreignId($table, 'registered_by', on: 'users', safeForSqlServer: true);
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
            DbCompat::foreignId($table, 'water_order_id', on: 'water_orders', onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            $table->string('parameter');                 // pH, loyqalik, element...
            $table->decimal('value', 20, 6)->nullable();
            $table->string('unit')->nullable();
            // Me'yor chegaralari — "normada" yoki "yomonlashish" aniqlash uchun
            $table->decimal('norm_min', 20, 6)->nullable();
            $table->decimal('norm_max', 20, 6)->nullable();
            $table->enum('assessment', ['normal', 'warning', 'critical', 'unknown'])->default('unknown');
            $table->timestamp('measured_at')->nullable();
            DbCompat::foreignId($table, 'entered_by', on: 'users', safeForSqlServer: true);
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
