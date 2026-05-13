<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['member_master_guides', 'staff_master_guides'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'emergency_contact_email')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('emergency_contact_email')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['member_master_guides', 'staff_master_guides'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'emergency_contact_email')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('emergency_contact_email');
            });
        }
    }
};
