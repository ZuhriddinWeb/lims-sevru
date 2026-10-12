<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratoriyalararo tekshiruv moduli (interlab).
 *
 * Faqat YANGI jadvallar yaratadi — mavjud jadvallarga tegmaydi.
 *   interlab_identity  — shu laboratoriya (tugun) va uning kalitlari
 *   interlab_peers     — ulangan boshqa laboratoriyalar
 *   interlab_messages  — yuborilgan/qabul qilingan xabarlar (navbat + jurnal)
 *   interlab_referrals — tekshiruv yo'llanmalari (chiquvchi va kiruvchi)
 *   interlab_results   — tekshiruv natijalari (har bir tahrir saqlanadi)
 *
 * SQL Server: bitta cascade yo'li (results → referrals), qolganlari indeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interlab_identity', function (Blueprint $table) {
            $table->id();
            $table->char('node_id', 36)->unique();
            $table->string('name', 191);
            $table->string('code', 20);
            $table->string('base_url', 255)->nullable();
            $table->text('enc_public');
            $table->text('enc_private');   // Laravel Crypt bilan shifrlangan
            $table->text('sign_public');
            $table->text('sign_private');  // Laravel Crypt bilan shifrlangan
            $table->string('fingerprint', 64);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::create('interlab_peers', function (Blueprint $table) {
            $table->id();
            $table->char('node_id', 36)->unique();
            $table->string('name', 191);
            $table->string('code', 20)->nullable();
            $table->string('base_url', 255);
            $table->text('enc_public');
            $table->text('sign_public');
            $table->string('fingerprint', 64);
            // pending_out — biz so'rov yubordik, ular tasdiqlashi kutilmoqda
            // pending_in  — ular so'rov yubordi, bizning admin tasdiqlashi kerak
            // active | rejected | disabled
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('interlab_messages', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('peer_id')->nullable()->index();
            $table->char('peer_node_id', 36)->nullable();
            $table->string('direction', 3);              // out | in
            $table->string('type', 40);
            $table->char('referral_uuid', 36)->nullable()->index();
            $table->longText('body')->nullable();       // ochiq JSON (faqat shu serverda)
            $table->longText('envelope')->nullable();   // shifrlangan + imzolangan konvert
            // out: pending | sending | sent | failed     in: processed | rejected
            $table->string('status', 16)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
        });

        Schema::create('interlab_referrals', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('number', 40)->index();
            $table->string('direction', 3);                 // out | in
            $table->unsignedBigInteger('peer_id')->index();
            // sending | sent | accepted | rejected | completed | cancelled
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('sample_id')->nullable()->index(); // faqat chiquvchida
            $table->string('sample_code', 64)->nullable();
            $table->string('sample_number', 64)->nullable();
            $table->boolean('hide_code')->default(false);
            $table->text('sample_info')->nullable();        // JSON
            $table->text('elements');                       // JSON [{symbol, name_uz, name_ru, name_en, unit}]
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->date('deadline')->nullable();
            $table->decimal('tolerance_pct', 8, 3)->nullable();
            $table->string('sender_name', 191)->nullable();
            $table->string('sender_position', 191)->nullable();
            $table->string('sender_lab', 191)->nullable();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_name', 191)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('result_revision')->default(0);
            $table->text('result_note')->nullable();
            $table->text('revision_reason')->nullable();
            $table->string('performer_name', 191)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by_name', 191)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
        });

        Schema::create('interlab_results', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'referral_id', 'id', 'interlab_referrals', 'cascadeOnDelete', false, true);
            $table->unsignedInteger('revision');
            $table->boolean('is_draft')->default(false);
            $table->string('symbol', 16);
            $table->string('value_text', 64)->nullable();
            $table->decimal('value', 24, 8)->nullable();
            $table->string('unit', 32)->nullable();
            $table->string('method', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->string('entered_by_name', 191)->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamps();

            $table->index(['referral_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interlab_results');
        Schema::dropIfExists('interlab_referrals');
        Schema::dropIfExists('interlab_messages');
        Schema::dropIfExists('interlab_peers');
        Schema::dropIfExists('interlab_identity');
    }
};
