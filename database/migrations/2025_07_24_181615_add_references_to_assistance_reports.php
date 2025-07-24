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
        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            // Add reference columns
            if (!Schema::hasColumn('rep_assistance_adv', 'class_id')) {
                $table->unsignedBigInteger('class_id')->nullable()->after('district');
            }
        
            if (!Schema::hasColumn('rep_assistance_adv', 'staff_id')) {
                $table->unsignedBigInteger('staff_id')->nullable()->after('class_id');
            }
        
            if (!Schema::hasColumn('rep_assistance_adv', 'church_id')) {
                $table->unsignedBigInteger('church_id')->nullable()->after('staff_id');
            }

        });

        if (Schema::hasColumn('rep_assistance_adv_merits', 'counselor')) {
            Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
                $table->renameColumn('counselor', 'staff_name');
            });
        }

        if (Schema::hasColumn('rep_assistance_adv_merits', 'applicant_name')) {
            Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
                $table->renameColumn('applicant_name', 'mem_adv_name');
            });
        }

        // Add mem_adv_name if it doesn't exist
        if (!Schema::hasColumn('rep_assistance_adv_merits', 'mem_adv_name')) {
            Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
                $table->string('mem_adv_name')->nullable();
            });
        }

        // Add mem_adv_id if it doesn't exist
        if (!Schema::hasColumn('rep_assistance_adv_merits', 'mem_adv_id')) {
            Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
                $table->unsignedBigInteger('mem_adv_id')->nullable()->after('mem_adv_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            $table->dropColumn(['class_id', 'staff_id', 'church_id']);

            // Revert column name
            $table->renameColumn('staff_name', 'counselor');
        });

        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->renameColumn('mem_adv_name', 'applicant_name');
            $table->dropColumn('mem_adv_id');
        });
    }
};
