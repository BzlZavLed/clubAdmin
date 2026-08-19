<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundraiser_sales', function (Blueprint $table) {
            $table->boolean('is_cancelled')->default(false)->after('notes')->index();
            $table->timestamp('cancelled_at')->nullable()->after('is_cancelled');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_user_id');
            $table->foreignId('reversal_payment_id')->nullable()->after('cancellation_reason')->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fundraiser_sales', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropForeign(['reversal_payment_id']);
            $table->dropIndex(['is_cancelled']);
            $table->dropColumn([
                'is_cancelled',
                'cancelled_at',
                'cancelled_by_user_id',
                'cancellation_reason',
                'reversal_payment_id',
            ]);
        });
    }
};
