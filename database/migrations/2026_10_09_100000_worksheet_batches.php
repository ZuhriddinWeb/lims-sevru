<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ishchi varaqlar — partiya rejimi (bir varaqda bir nechta proba).
 *
 * Faqat QO'SHADI: yangi jadval va yangi ustunlar. Mavjud ma'lumot o'chirilmaydi.
 * Avval yaratilgan varaqlar «1 probalik partiya» bo'lib qoladi (pastdagi to'ldirish).
 *
 *   worksheet_samples     — varaq ↔ probalar (tartibi, parallel soni, «takroriy tahlil» belgisi)
 *   worksheet_rows        — + sample_id, parallel_no, is_extra (qo'shimcha parallel), added_by
 *   worksheet_templates   — + max_samples (bir varaqdagi eng ko'p proba soni, bo'sh — cheklanmagan)
 *
 * SQL Server: worksheet_samples'ga bitta CASCADE yo'li (worksheets'dan), probaga — ON DELETE'siz
 * (NO ACTION). «restrict» so'zi ishlatilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worksheet_samples')) {
            Schema::create('worksheet_samples', function (Blueprint $table) {
                $table->id();
                DbCompat::foreignId($table, 'worksheet_id', on: 'worksheets',
                    onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);

                $table->unsignedBigInteger('sample_id');
                $table->index('sample_id');
                $table->foreign('sample_id')->references('id')->on('samples');

                $table->unsignedSmallInteger('position')->default(1);    // varaqdagi tartibi
                $table->unsignedTinyInteger('parallels')->default(2);    // yaratishda rejalashtirilgan
                $table->string('status', 20)->default('active');         // active | retest
                $table->string('retest_reason', 500)->nullable();
                $table->unsignedBigInteger('retest_by')->nullable()->index();
                $table->timestamp('retest_at')->nullable();
                $table->timestamp('result_pushed_at')->nullable();
                $table->timestamps();

                $table->unique(['worksheet_id', 'sample_id']);
                $table->index('status');
            });
        }

        if (! Schema::hasColumn('worksheet_rows', 'sample_id')) {
            Schema::table('worksheet_rows', function (Blueprint $table) {
                $table->unsignedBigInteger('sample_id')->nullable();
                $table->unsignedTinyInteger('parallel_no')->nullable();
                $table->boolean('is_extra')->default(false);
                $table->unsignedBigInteger('added_by')->nullable();
                $table->index('sample_id');
            });
        }

        if (! Schema::hasColumn('worksheet_templates', 'max_samples')) {
            Schema::table('worksheet_templates', function (Blueprint $table) {
                $table->unsignedTinyInteger('max_samples')->nullable();
            });
        }

        $this->backfill();
    }

    /** Eski varaqlar: probasi worksheet_samples'ga, qatorlari probaga bog'lanadi */
    private function backfill(): void
    {
        DB::table('worksheets')
            ->select(['id', 'sample_id', 'results_pushed_at'])
            ->orderBy('id')
            ->chunkById(200, function ($list) {
                foreach ($list as $w) {
                    $rowCount = DB::table('worksheet_rows')->where('worksheet_id', $w->id)->count();

                    $exists = DB::table('worksheet_samples')
                        ->where('worksheet_id', $w->id)->where('sample_id', $w->sample_id)->exists();
                    if (! $exists) {
                        DB::table('worksheet_samples')->insert([
                            'worksheet_id'     => $w->id,
                            'sample_id'        => $w->sample_id,
                            'position'         => 1,
                            'parallels'        => max(1, min(255, $rowCount ?: 1)),
                            'status'           => 'active',
                            'result_pushed_at' => $w->results_pushed_at,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    }

                    DB::table('worksheet_rows')
                        ->where('worksheet_id', $w->id)->whereNull('sample_id')
                        ->update(['sample_id' => $w->sample_id]);
                    DB::table('worksheet_rows')
                        ->where('worksheet_id', $w->id)->whereNull('parallel_no')
                        ->update(['parallel_no' => DB::raw('row_no')]);
                }
            });
    }

    /**
     * DIQQAT: bir nechta probali varaqlar yaratilgan bo'lsa, orqaga qaytarish ularning
     * probalar ro'yxatini yo'qotadi (qatorlar varaqda qoladi). Faqat sinov bosqichida ishlating.
     */
    public function down(): void
    {
        Schema::dropIfExists('worksheet_samples');

        if (Schema::hasColumn('worksheet_rows', 'sample_id')) {
            Schema::table('worksheet_rows', function (Blueprint $table) {
                $table->dropIndex(['sample_id']);
                $table->dropColumn(['sample_id', 'parallel_no', 'is_extra', 'added_by']);
            });
        }

        if (Schema::hasColumn('worksheet_templates', 'max_samples')) {
            Schema::table('worksheet_templates', function (Blueprint $table) {
                $table->dropColumn('max_samples');
            });
        }
    }
};
