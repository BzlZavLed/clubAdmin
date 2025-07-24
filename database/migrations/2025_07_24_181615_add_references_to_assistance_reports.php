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
            $table->unsignedBigInteger('class_id')->nullable()->after('district');
            $table->unsignedBigInteger('staff_id')->nullable()->after('class_id');
            $table->unsignedBigInteger('church_id')->nullable()->after('staff_id');

            // Rename counselor to staff_name
            $table->renameColumn('counselor', 'staff_name');
        });

        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            // Rename applicant_name to mem_adv_name
            $table->renameColumn('applicant_name', 'mem_adv_name');

            // Add applicant_id renamed to mem_adv_id
            $table->unsignedBigInteger('mem_adv_id')->nullable()->after('mem_adv_name');
        });
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
