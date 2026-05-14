<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments') || Schema::hasColumn('payments', 'payer_name')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payer_name')->nullable()->after('staff_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payments') || !Schema::hasColumn('payments', 'payer_name')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payer_name');
        });
    }
};
