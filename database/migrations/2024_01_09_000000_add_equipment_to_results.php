<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TZ 1.8 — qaysi uskunada qaysi sinov o'tkazilgani
        Schema::table('sample_elements', function (Blueprint $table) {
            // SQL Server: sample_elements'da allaqachon cascade bor — bu FK indeks bo'ladi
            DbCompat::foreignId($table, 'equipment_id', on: 'equipment');
            $table->string('method')->nullable(); // Sinov usuli
        });

        Schema::table('water_order_results', function (Blueprint $table) {
            DbCompat::foreignId($table, 'equipment_id', on: 'equipment', safeForSqlServer: true);
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
            if (! DbCompat::isSqlServer()) {
                $table->dropForeign(['equipment_id']);
            }
            $table->dropColumn('equipment_id');
        });

        Schema::table('sample_elements', function (Blueprint $table) {
            if (! DbCompat::isSqlServer()) {
                $table->dropForeign(['equipment_id']);
            }
            $table->dropColumn(['equipment_id', 'method']);
        });
    }
};
