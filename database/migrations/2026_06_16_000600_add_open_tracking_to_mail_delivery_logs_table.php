<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('sent_at');
            $table->unsignedInteger('open_count')->default(0)->after('opened_at');
            $table->timestamp('last_opened_at')->nullable()->after('open_count');
            $table->string('last_open_ip')->nullable()->after('last_opened_at');
            $table->text('last_open_user_agent')->nullable()->after('last_open_ip');
        });
    }

    public function down(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->dropColumn([
                'opened_at',
                'open_count',
                'last_opened_at',
                'last_open_ip',
                'last_open_user_agent',
            ]);
        });
    }
};
