<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 33]
 *   portal_request_templates     — buyurtmachi bo'linmasining buyurtma shablonlari (faqat shu bo'linma ko'radi)
 *   sample_requests.handed_over_phone — namunani olib kelgan shaxsning telefoni (qabulda)
 *   samples.received_by          — namunani qabul qilib olgan laboratoriya xodimi (ro'yxatdan tanlanadi)
 *
 * Qayta ishga tushirish xavfsiz: har bir qism faqat yo'q bo'lsa qo'shiladi. Mavjud ma'lumotga tegilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_request_templates')) {
            Schema::create('portal_request_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->string('name', 120);
                $table->integer('sort_order')->default(0);
                $table->text('payload')->nullable();               // JSON: sarlavha maydonlari + namunalar
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sample_requests') && ! Schema::hasColumn('sample_requests', 'handed_over_phone')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->string('handed_over_phone', 64)->nullable();
            });
        }

        if (Schema::hasTable('samples') && ! Schema::hasColumn('samples', 'received_by')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->unsignedBigInteger('received_by')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('samples', 'received_by')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->dropIndex(['received_by']);
                $table->dropColumn('received_by');
            });
        }
        if (Schema::hasColumn('sample_requests', 'handed_over_phone')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->dropColumn('handed_over_phone');
            });
        }
        Schema::dropIfExists('portal_request_templates');
    }
};
