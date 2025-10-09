<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->default(0)->after('payment_expected_by');
            $table->index('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->dropIndex(['amount']);
            $table->dropColumn('amount');
        });
    }
};
