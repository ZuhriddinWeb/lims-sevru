<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable(); // Lavozim
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Ruxsat tizimi va laboratoriya bog'lanishlari (FK'lar oxirgi migratsiyada qo'shiladi)
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->unsignedBigInteger('laboratory_id')->nullable()->index();
            $table->unsignedBigInteger('laboratory_group_id')->nullable()->index();

            // Foydalanuvchi sozlamalari
            $table->string('locale', 5)->default('ru');   // ru | uz | en
            $table->string('theme', 10)->default('light'); // light | dark
            $table->string('avatar')->nullable();
            $table->boolean('is_active')->default(true);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        // SQL Server: NULL email'larni hisobga olmaydigan filtrlangan unikal indeks.
// (Boshqa bazalarda ham ishlaydi — odatiy unikal indeks kabi.)
if (DB::connection()->getDriverName() === 'sqlsrv') {
    DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
} else {
    Schema::table('users', function (Blueprint $table) {
        $table->unique('email');
    });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
