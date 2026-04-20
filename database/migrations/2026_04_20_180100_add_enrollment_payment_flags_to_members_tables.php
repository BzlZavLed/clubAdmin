<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->boolean('enrollment_paid')->default(false)->after('insurance_paid_at');
            $table->timestamp('enrollment_paid_at')->nullable()->after('enrollment_paid');
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->boolean('enrollment_paid')->default(false)->after('insurance_paid_at');
            $table->timestamp('enrollment_paid_at')->nullable()->after('enrollment_paid');
        });
    }

    public function down(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->dropColumn(['enrollment_paid', 'enrollment_paid_at']);
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->dropColumn(['enrollment_paid', 'enrollment_paid_at']);
        });
    }
};
