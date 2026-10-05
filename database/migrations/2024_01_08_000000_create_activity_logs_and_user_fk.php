<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit — o'zgarishlar tarixi
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');          // created | updated | deleted | login | logout
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('description')->nullable();
            $table->json('properties')->nullable(); // o'zgargan maydonlar
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['model_type', 'model_id']);
            $table->index('action');
        });

        // Users jadvaliga foreign key'lar (barcha jadvallar yaratilgandan keyin)
        // Schema::table('users', function (Blueprint $table) {
        //     $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        //     $table->foreign('laboratory_id')->references('id')->on('laboratories')->nullOnDelete();
        //     $table->foreign('laboratory_group_id')->references('id')->on('laboratory_groups')->nullOnDelete();
        // });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['laboratory_id']);
            $table->dropForeign(['laboratory_group_id']);
        });
        Schema::dropIfExists('activity_logs');
    }
};
