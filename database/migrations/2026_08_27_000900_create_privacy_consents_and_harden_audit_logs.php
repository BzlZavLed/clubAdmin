<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notice_version', 32)->index();
            $table->char('notice_hash', 64);
            $table->char('subject_email_hash', 64)->index();
            $table->string('source', 64)->index();
            $table->string('locale', 10)->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('consented_at')->index();
            $table->timestamps();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('event_uuid')->nullable()->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->char('integrity_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['event_uuid', 'request_id', 'integrity_hash']);
        });

        Schema::dropIfExists('privacy_consents');
    }
};
