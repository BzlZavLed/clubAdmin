<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('email_uid');
            $table->string('provider_message_id')->nullable()->after('provider');
            $table->timestamp('last_provider_event_at')->nullable()->after('provider_message_id');

            $table->index(['provider', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_message_id']);
            $table->dropColumn([
                'provider',
                'provider_message_id',
                'last_provider_event_at',
            ]);
        });
    }
};
