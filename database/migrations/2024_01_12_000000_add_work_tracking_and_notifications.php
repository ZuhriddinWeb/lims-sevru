<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TZ — "берут на работу": ishni kim va qachon boshlagani
        Schema::table('samples', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('assigned_at');
            // SQL Server bir nechta SET NULL yo'lini qabul qilmaydi — FK siz, faqat indeks
            $table->unsignedBigInteger('started_by')->nullable()->index()->after('started_at');
        });

        Schema::table('water_orders', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('registered_at');
            $table->unsignedBigInteger('started_by')->nullable()->index()->after('started_at');
        });

        // TZ 1.5 — "или другим условиям": ixtiyoriy kalibrovka sharti
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('custom_condition')->nullable()->after('max_usage_before_calibration');
            $table->date('custom_condition_due')->nullable()->after('custom_condition');
        });

        // Faol eslatmalar
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // null — hammaga
            $table->string('type');                 // calibration | maintenance | storage | sample
            $table->string('level')->default('info'); // info | warning | critical
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('link_route')->nullable();
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('dedup_key')->nullable()->index(); // takrorlanmasligi uchun
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['custom_condition', 'custom_condition_due']);
        });

        Schema::table('water_orders', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'started_by']);
        });

        Schema::table('samples', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'started_by']);
        });
    }
};
