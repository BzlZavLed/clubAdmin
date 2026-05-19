<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundraiser_sales', function (Blueprint $table) {
            if (!Schema::hasColumn('fundraiser_sales', 'kitchen_status')) {
                $table->string('kitchen_status', 32)->default('pending')->index();
            }

            if (!Schema::hasColumn('fundraiser_sales', 'kitchen_completed_at')) {
                $table->timestamp('kitchen_completed_at')->nullable();
            }

            if (!Schema::hasColumn('fundraiser_sales', 'kitchen_completed_by_user_id')) {
                $table->foreignId('kitchen_completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fundraiser_sales', function (Blueprint $table) {
            if (Schema::hasColumn('fundraiser_sales', 'kitchen_completed_by_user_id')) {
                $table->dropConstrainedForeignId('kitchen_completed_by_user_id');
            }

            if (Schema::hasColumn('fundraiser_sales', 'kitchen_completed_at')) {
                $table->dropColumn('kitchen_completed_at');
            }

            if (Schema::hasColumn('fundraiser_sales', 'kitchen_status')) {
                $table->dropColumn('kitchen_status');
            }
        });
    }
};
