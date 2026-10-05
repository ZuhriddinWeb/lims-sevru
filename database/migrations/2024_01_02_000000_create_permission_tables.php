<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rollar
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // slug: admin, lab_head, operator...
            $table->string('display_name_ru')->nullable();
            $table->string('display_name_uz')->nullable();
            $table->string('display_name_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false); // tizim rollari o'chirilmaydi
            $table->timestamps();
        });

        // Ruxsatlar — har bir menu (modul) + amal (CRUD) kombinatsiyasi
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();   // masalan: equipment.view, equipment.create
            $table->string('module');           // menu/modul slug: equipment, samples...
            $table->string('ability');          // view | create | update | delete | export | manage
            $table->string('group')->default('general'); // UI uchun guruhlash: catalog, lab, admin
            $table->string('label_ru')->nullable();
            $table->string('label_uz')->nullable();
            $table->string('label_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['module', 'ability']);
        });

        // Rol — Ruxsat (ko'pdan-ko'p)
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Menu elementlari — sidebar menyusini DB'dan boshqarish uchun
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();        // dashboard, equipment, samples...
            $table->string('title_ru');
            $table->string('title_uz');
            $table->string('title_en');
            $table->string('icon')->nullable();     // lucide ikonka nomi
            $table->string('route')->nullable();    // vue route nomi
            $table->string('permission')->nullable(); // kerakli ruxsat: equipment.view
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
