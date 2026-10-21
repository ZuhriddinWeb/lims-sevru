<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtma so'rovlari (qoralama buyurtma) — Paket 24.
 *
 * Buyurtmachi bo'lim portalda so'rov tuzadi (qoralama) va yuboradi. So'rov hali proba EMAS:
 * shifr berilmaydi, probalar ro'yxati/hisobotlarga tushmaydi. Namuna jismonan kelganda
 * laboratoriya «Qabul qilish» ni bosadi — shunda haqiqiy probalar (samples) yaratiladi.
 *
 *   sample_requests       — so'rov (sarlavha): bo'lim, holat, raqam, kim/qachon
 *   sample_request_items  — so'rovdagi namunalar: shifr, holat, nima aniqlanadi, miqdor
 *   samples.handed_over_by — namunani topshirgan shaxs (reyestr: «topshirgan FIO»)
 *
 * Faqat QO'SHADI: mavjud jadval va ma'lumotlarga tegmaydi. Tashqi kalitlar SQL Server uchun —
 * faqat indeks (bog'lanish Eloquent darajasida).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sample_requests')) {
            Schema::create('sample_requests', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->nullable();              // B-2026-0001 — yuborilganda beriladi
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->string('origin', 10)->default('portal');        // portal | lab (xodim buyurtmachi nomidan)
                $table->string('status', 20)->default('draft')->index(); // draft|submitted|partial|received|rejected|cancelled|expired
                $table->date('delivery_date')->nullable();              // namuna qachon olib kelinadi (reja)
                $table->string('contact_name', 191)->nullable();        // kim olib keladi
                $table->string('contact_phone', 64)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('received_at')->nullable();           // birinchi qabul
                $table->unsignedBigInteger('received_by')->nullable()->index();
                $table->string('handed_over_by', 191)->nullable();      // amalda olib kelgan shaxs
                $table->timestamp('closed_at')->nullable();             // rad etildi / bekor qilindi / to'liq qabul
                $table->unsignedBigInteger('closed_by')->nullable()->index();
                $table->string('close_reason', 500)->nullable();
                $table->timestamps();
            });
            DbCompat::nullableUnique('sample_requests', 'number');
        }

        if (! Schema::hasTable('sample_request_items')) {
            Schema::create('sample_request_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sample_request_id')->index();
                $table->unsignedSmallInteger('position')->default(1);
                $table->string('customer_code', 64)->nullable();        // bo'lim shifri (yorliqdagi)
                $table->string('state_of_matter', 10)->default('solid'); // liquid | solid
                $table->unsignedBigInteger('test_profile_id')->nullable()->index();
                $table->text('element_ids')->nullable();                // JSON: [1,2,3]
                $table->decimal('quantity', 14, 4)->nullable();         // taxminiy miqdor (buyurtmachi)
                $table->string('quantity_unit', 8)->nullable();
                $table->string('notes', 500)->nullable();
                $table->string('status', 12)->default('pending')->index(); // pending | received | rejected
                $table->unsignedBigInteger('sample_id')->nullable()->index(); // qabul qilingach yaratilgan proba
                $table->timestamps();
            });
        }

        if (Schema::hasTable('samples') && ! Schema::hasColumn('samples', 'handed_over_by')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->string('handed_over_by', 191)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('samples', 'handed_over_by')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->dropColumn('handed_over_by');
            });
        }
        Schema::dropIfExists('sample_request_items');
        Schema::dropIfExists('sample_requests');
    }
};
