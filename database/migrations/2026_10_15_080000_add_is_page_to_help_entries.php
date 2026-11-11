<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yordamchi: sahifaning umumiy tavsifi belgisi (sahifa ochilganda birinchi ko'rsatiladi). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('help_entries') && ! Schema::hasColumn('help_entries', 'is_page')) {
            Schema::table('help_entries', function (Blueprint $table) {
                $table->boolean('is_page')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('help_entries', 'is_page')) {
            Schema::table('help_entries', function (Blueprint $table) {
                $table->dropColumn('is_page');
            });
        }
    }
};
