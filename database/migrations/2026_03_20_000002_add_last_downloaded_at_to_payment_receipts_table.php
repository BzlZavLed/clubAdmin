<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->timestamp('last_downloaded_at')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('last_downloaded_at');
        });
    }
};
