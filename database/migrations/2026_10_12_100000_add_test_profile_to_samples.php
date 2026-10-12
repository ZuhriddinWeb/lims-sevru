<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proba qaysi sinov profili bo'yicha ro'yxatga olingani (tahrirlashda ko'rsatish uchun)
        if (! Schema::hasColumn('samples', 'test_profile_id')) {
            Schema::table('samples', function (Blueprint $table) {
                DbCompat::foreignId($table, 'test_profile_id', on: 'test_profiles');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('samples', 'test_profile_id')) {
            DbCompat::dropForeignIfExists('samples', 'test_profile_id');
            Schema::table('samples', function (Blueprint $table) {
                $table->dropIndex(['test_profile_id']);
                $table->dropColumn('test_profile_id');
            });
        }
    }
};
