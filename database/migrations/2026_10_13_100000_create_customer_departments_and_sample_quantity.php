<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtmachi bo'limlar va proba miqdori hisobi.
 *
 * Faqat QO'SHADI — mavjud ma'lumot o'zgarmaydi, o'chirilmaydi:
 *   customer_departments       — zavoddagi buyurtmachi bo'limlar katalogi
 *   customer_department_user   — bo'lim ↔ «Buyurtmachi» foydalanuvchilari (portalga kiradiganlar)
 *   samples                    — + customer_department_id, customer_code (bo'lim bergan shifr),
 *                                  received_quantity + quantity_unit (qabulda o'lchangan miqdor),
 *                                  retained_quantity (arxiv/arbitraj uchun ajratilgan qism)
 *   sample_quantity_movements  — qo'lda yoziladigan harakatlar: boshqa sarf, qaytarish,
 *                                  utilizatsiya, tuzatish (ishchi varaq sarfi avtomatik hisoblanadi)
 *
 * SQL Server: yangi bog'lanishlar DbCompat orqali (xavfli FK o'rniga indeks), «restrict» ishlatilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_departments')) {
            Schema::create('customer_departments', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->nullable();          // qisqa belgi (masalan: GMZ3-S2)
                $table->string('name', 255);
                $table->string('contact_person', 191)->nullable();
                $table->string('phone', 64)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index('name');
                $table->index('is_active');
            });
            DbCompat::nullableUnique('customer_departments', 'code');
        }

        if (! Schema::hasTable('customer_department_user')) {
            Schema::create('customer_department_user', function (Blueprint $table) {
                $table->id();
                DbCompat::foreignId($table, 'customer_department_id', on: 'customer_departments',
                    onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
                // users — ko'p bog'lanishli jadval: SQL Server'da faqat indeks
                DbCompat::foreignId($table, 'user_id', on: 'users', onDelete: 'cascadeOnDelete', nullable: false);
                $table->timestamps();

                $table->unique(['customer_department_id', 'user_id']);
            });
        }

        Schema::table('samples', function (Blueprint $table) {
            if (! Schema::hasColumn('samples', 'customer_department_id')) {
                DbCompat::foreignId($table, 'customer_department_id', on: 'customer_departments', onDelete: 'nullOnDelete');
            }
            if (! Schema::hasColumn('samples', 'customer_code')) {
                $table->string('customer_code', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('samples', 'received_quantity')) {
                $table->decimal('received_quantity', 14, 4)->nullable();
            }
            if (! Schema::hasColumn('samples', 'quantity_unit')) {
                $table->string('quantity_unit', 8)->nullable();
            }
            if (! Schema::hasColumn('samples', 'retained_quantity')) {
                $table->decimal('retained_quantity', 14, 4)->nullable();
            }
        });

        if (! Schema::hasTable('sample_quantity_movements')) {
            Schema::create('sample_quantity_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sample_id');
                $table->index('sample_id');
                $table->foreign('sample_id')->references('id')->on('samples'); // ON DELETE'siz (NO ACTION)

                $table->string('kind', 20);                 // other | return | dispose | correction
                $table->decimal('delta', 14, 4);            // manfiy — sarf, musbat — qo'shimcha (tuzatish)
                $table->string('unit', 8);
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index('kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_quantity_movements');

        if (Schema::hasColumn('samples', 'customer_department_id')) {
            DbCompat::dropForeignIfExists('samples', 'customer_department_id');
            Schema::table('samples', function (Blueprint $table) {
                $table->dropIndex(['customer_department_id']);
                $table->dropIndex(['customer_code']);
            });
            Schema::table('samples', function (Blueprint $table) {
                $table->dropColumn(['customer_department_id', 'customer_code', 'received_quantity', 'quantity_unit', 'retained_quantity']);
            });
        }

        Schema::dropIfExists('customer_department_user');
        Schema::dropIfExists('customer_departments');
    }
};
