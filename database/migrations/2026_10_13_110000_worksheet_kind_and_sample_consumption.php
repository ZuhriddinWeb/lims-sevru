<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ishchi varaq turi va proba sarfi ustuni.
 *
 *   worksheets.kind                         — main (asosiy) | control (nazorat uchun). Eski varaqlar — main.
 *   worksheet_template_columns.consumes_sample — shu ustun qiymati probadan olingan miqdor (naveska).
 *        Mavjud shablonlarda «mass» kalitli kiritish ustuni avtomatik belgilanadi
 *        (agar shablonda boshqa belgilangan ustun bo'lmasa). Tahrirlangan shablonlarning
 *        boshqa maydonlariga tegilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('worksheets', 'kind')) {
            Schema::table('worksheets', function (Blueprint $table) {
                $table->string('kind', 16)->default('main');
                $table->index('kind');
            });
        }

        if (! Schema::hasColumn('worksheet_template_columns', 'consumes_sample')) {
            Schema::table('worksheet_template_columns', function (Blueprint $table) {
                $table->boolean('consumes_sample')->default(false);
            });

            $flagged = DB::table('worksheet_template_columns')->where('consumes_sample', true)->pluck('template_id')->all();
            DB::table('worksheet_template_columns')
                ->where('key', 'mass')->where('scope', 'row')->where('type', 'input')
                ->when($flagged, fn ($q) => $q->whereNotIn('template_id', $flagged))
                ->update(['consumes_sample' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('worksheet_template_columns', 'consumes_sample')) {
            Schema::table('worksheet_template_columns', function (Blueprint $table) {
                $table->dropColumn('consumes_sample');
            });
        }
        if (Schema::hasColumn('worksheets', 'kind')) {
            Schema::table('worksheets', function (Blueprint $table) {
                $table->dropIndex(['kind']);
            });
            Schema::table('worksheets', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
