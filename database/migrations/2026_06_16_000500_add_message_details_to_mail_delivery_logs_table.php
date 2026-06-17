<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->string('from_email')->nullable()->after('mailable');
            $table->string('from_name')->nullable()->after('from_email');
            $table->string('source_label')->nullable()->after('subject');
            $table->string('destination_label')->nullable()->after('source_label');
            $table->longText('body_html')->nullable()->after('error_message');
            $table->longText('body_text')->nullable()->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->dropColumn([
                'from_email',
                'from_name',
                'source_label',
                'destination_label',
                'body_html',
                'body_text',
            ]);
        });
    }
};
