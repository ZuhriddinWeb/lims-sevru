<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TZ 2.8 — sinov obyektining hamroh hujjatlari (nakladnoy, qabul akti)
        Schema::create('test_object_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_object_id')->constrained('test_objects')->cascadeOnDelete();
            $table->enum('type', ['waybill', 'acceptance_act', 'disposal_act', 'protocol', 'other'])->default('other');
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Uskuna hujjatlariga asl fayl nomini qo'shish
        Schema::table('equipment_documents', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_object_documents');
        Schema::table('equipment_documents', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });
    }
};
