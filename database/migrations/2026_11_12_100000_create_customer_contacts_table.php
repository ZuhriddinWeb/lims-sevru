<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 34] Buyurtmachi bo'linmasi xodimlari ma'lumotnomasi («Kim olib keladi» + telefon).
 * Foydalanuvchi alohida to'ldirmaydi — buyurtma yuborilganda va laboratoriya qabul qilganda o'zi yig'iladi.
 *   name_key — solishtirish kaliti (kichik harf, lotin yozuvida, tinish belgilarisiz): bo'linma ichida yagona.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_contacts')) {
            return;
        }
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_department_id');
            $table->string('full_name', 191);
            $table->string('name_key', 191);
            $table->string('phone', 64)->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['customer_department_id', 'name_key'], 'customer_contacts_dept_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
