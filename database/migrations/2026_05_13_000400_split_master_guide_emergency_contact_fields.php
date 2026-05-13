<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->splitTable('member_master_guides');
        $this->splitTable('staff_master_guides');
    }

    public function down(): void
    {
        foreach (['member_master_guides', 'staff_master_guides'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'emergency_contact')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('emergency_contact')->nullable();
            });

            DB::table($tableName)->update([
                'emergency_contact' => DB::raw('emergency_contact_name'),
            ]);
        }
    }

    private function splitTable(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'emergency_contact_name')) {
                $table->string('emergency_contact_name')->nullable();
            }

            if (!Schema::hasColumn($tableName, 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 50)->nullable();
            }

            if (!Schema::hasColumn($tableName, 'emergency_contact_email')) {
                $table->string('emergency_contact_email')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'emergency_contact')) {
            DB::table($tableName)
                ->whereNull('emergency_contact_name')
                ->whereNotNull('emergency_contact')
                ->update(['emergency_contact_name' => DB::raw('emergency_contact')]);
        }
    }
};
