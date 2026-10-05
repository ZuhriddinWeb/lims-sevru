<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uskunalar (Оборудование) — TZ 1.3
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained('laboratories')->cascadeOnDelete();
            $table->unsignedBigInteger('laboratory_group_id')->nullable()->index();

            $table->string('name');                         // Nomi
            $table->string('model')->nullable();            // Model
            $table->string('serial_number')->unique();      // Seriya raqami
            $table->string('inventory_number')->nullable(); // Inventar raqami
            $table->string('manufacturer')->nullable();     // Ishlab chiqaruvchi
            $table->string('supplier')->nullable();         // Yetkazib beruvchi
            $table->string('location')->nullable();         // Joylashuvi
            $table->date('commissioned_at')->nullable();    // Ishga tushirilgan sana

            // Holati — TZ 1.6
            $table->enum('status', [
                'working',            // Rabочее
                'maintenance',        // На обслуживании
                'out_of_service',     // Вне эксплуатации
                'awaiting_calibration'// Ожидает калибровки
            ])->default('working');

            // Bloklash — TZ 1.7 (kalibrovka/xizmatdan o'tmasa bloklanadi)
            $table->boolean('is_blocked')->default(false);
            $table->string('block_reason')->nullable();

            // Kalibrovka / poverka — TZ 1.1, 1.5
            $table->unsignedInteger('calibration_interval_days')->nullable();
            $table->date('last_calibration_at')->nullable();
            $table->date('next_calibration_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('max_usage_before_calibration')->nullable();

            // Texnik xizmat — TZ 1.2
            $table->unsignedInteger('maintenance_interval_days')->nullable();
            $table->date('last_maintenance_at')->nullable();
            $table->date('next_maintenance_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('next_calibration_at');
        });

        // Kalibrovka / poverka tarixi — TZ 1.1
        Schema::create('equipment_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->enum('type', ['calibration', 'verification'])->default('calibration');
            $table->date('performed_at');
            $table->string('performed_by')->nullable();      // Kim/qaysi tashkilot
            $table->enum('result', ['passed', 'failed'])->default('passed');
            $table->string('certificate_number')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('document_path')->nullable();     // Sertifikat fayli
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Texnik xizmat / ta'mirlash / komponent almashtirish tarixi — TZ 1.2, 1.4
        Schema::create('equipment_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->enum('type', ['scheduled', 'repair', 'component_replacement'])->default('scheduled');
            $table->date('scheduled_at')->nullable();
            $table->date('performed_at')->nullable();
            $table->string('performed_by')->nullable();
            $table->text('description')->nullable();
            $table->text('components_replaced')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->enum('status', ['planned', 'in_progress', 'done', 'cancelled'])->default('planned');
            $table->string('document_path')->nullable();     // Akt fayli
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Uskuna hujjatlari (sertifikatlar, aktlar, yo'riqnomalar) — TZ 1.9
        Schema::create('equipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->enum('type', [
                'calibration_certificate',
                'maintenance_act',
                'manual',
                'other'
            ])->default('other');
            $table->string('title');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_documents');
        Schema::dropIfExists('equipment_maintenances');
        Schema::dropIfExists('equipment_calibrations');
        Schema::dropIfExists('equipment');
    }
};
