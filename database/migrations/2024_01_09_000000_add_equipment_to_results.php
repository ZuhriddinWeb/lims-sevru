<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TZ 1.8 — qaysi uskunada qaysi sinov o'tkazilgani
        Schema::table('sample_elements', function (Blueprint $table) {
            $table->foreignId('equipment_id')->nullable()->after('element_id')
                  ->constrained('equipment')->nullOnDelete();
            $table->string('method')->nullable()->after('result_unit'); // Sinov usuli
        });

        Schema::table('water_order_results', function (Blueprint $table) {
            $table->foreignId('equipment_id')->nullable()->after('unit')
                  ->constrained('equipment')->nullOnDelete();
        });

        // Uskuna ishlatilish jurnali — TZ 1.5 (ishlatilish soni bo'yicha eslatma)
        Schema::create('equipment_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('context_type');            // Sample | WaterOrder
            $table->unsignedBigInteger('context_id');
            $table->string('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index(['equipment_id', 'used_at']);
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_usages');

        Schema::table('water_order_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_id');
        });

        Schema::table('sample_elements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_id');
            $table->dropColumn('method');
        });
    }
};
