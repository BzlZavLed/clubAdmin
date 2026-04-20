<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->boolean('insurance_paid')->default(false)->after('status');
            $table->timestamp('insurance_paid_at')->nullable()->after('insurance_paid');
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->boolean('insurance_paid')->default(false)->after('status');
            $table->timestamp('insurance_paid_at')->nullable()->after('insurance_paid');
        });
    }

    public function down(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->dropColumn(['insurance_paid', 'insurance_paid_at']);
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->dropColumn(['insurance_paid', 'insurance_paid_at']);
        });
    }
};
