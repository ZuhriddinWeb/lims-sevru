<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratoriyalarni ulash uchun bir martalik kodlar (faqat YANGI jadval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interlab_invites', function (Blueprint $table) {
            $table->id();
            $table->char('lookup', 32)->unique();      // kodni topish uchun (sirning o'zi emas)
            $table->text('secret');                    // Laravel Crypt bilan shifrlangan
            $table->string('note', 191)->nullable();   // kim uchun
            $table->timestamp('expires_at')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_peer_id')->nullable()->index();
            $table->string('used_by_name', 191)->nullable();
            $table->string('used_ip', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interlab_invites');
    }
};
