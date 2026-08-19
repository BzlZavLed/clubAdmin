<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundraiser_events', function (Blueprint $table) {
            $table->string('accounting_mode', 24)->default('automatic')->after('pay_to')->index();
            $table->uuid('accounting_batch_uuid')->nullable()->unique()->after('status');
            $table->timestamp('accounting_posted_at')->nullable()->after('accounting_batch_uuid');
            $table->foreignId('accounting_posted_by_user_id')
                ->nullable()
                ->after('accounting_posted_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fundraiser_events', function (Blueprint $table) {
            $table->dropForeign(['accounting_posted_by_user_id']);
            $table->dropUnique(['accounting_batch_uuid']);
            $table->dropIndex(['accounting_mode']);
            $table->dropColumn([
                'accounting_mode',
                'accounting_batch_uuid',
                'accounting_posted_at',
                'accounting_posted_by_user_id',
            ]);
        });
    }
};
