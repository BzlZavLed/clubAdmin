<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('rep_assistance_adv')) {
            return;
        }

        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            // If legacy column exists, rename it
            if (Schema::hasColumn('rep_assistance_adv', 'counselor') && !Schema::hasColumn('rep_assistance_adv', 'staff_name')) {
                $table->renameColumn('counselor', 'staff_name');
            } elseif (!Schema::hasColumn('rep_assistance_adv', 'staff_name')) {
                $table->string('staff_name')->nullable()->after('class_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rep_assistance_adv')) {
            return;
        }

        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            if (Schema::hasColumn('rep_assistance_adv', 'staff_name') && !Schema::hasColumn('rep_assistance_adv', 'counselor')) {
                $table->renameColumn('staff_name', 'counselor');
            }
        });
    }
};
