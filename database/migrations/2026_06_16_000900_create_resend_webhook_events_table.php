<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resend_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('svix_id')->unique();
            $table->string('event_type')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->foreignId('mail_delivery_log_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resend_webhook_events');
    }
};
