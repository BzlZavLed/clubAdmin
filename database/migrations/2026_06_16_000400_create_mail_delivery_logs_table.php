<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->string('mail_key');
            $table->string('mailable')->nullable();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->default('pending');
            $table->nullableMorphs('loggable');
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['mail_key', 'created_at']);
            $table->index(['recipient_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_delivery_logs');
    }
};
