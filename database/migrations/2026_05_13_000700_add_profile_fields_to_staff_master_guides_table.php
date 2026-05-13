<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_master_guides')) {
            return;
        }

        Schema::table('staff_master_guides', function (Blueprint $table) {
            if (!Schema::hasColumn('staff_master_guides', 'dob')) {
                $table->date('dob')->nullable()->after('email');
            }

            if (!Schema::hasColumn('staff_master_guides', 'has_previous_staff_experience')) {
                $table->boolean('has_previous_staff_experience')->default(false)->after('dob');
            }

            if (!Schema::hasColumn('staff_master_guides', 'previous_staff_where')) {
                $table->text('previous_staff_where')->nullable()->after('has_previous_staff_experience');
            }

            if (!Schema::hasColumn('staff_master_guides', 'is_invested_master_guide')) {
                $table->boolean('is_invested_master_guide')->default(false)->after('previous_staff_where');
            }

            if (!Schema::hasColumn('staff_master_guides', 'investment_date')) {
                $table->date('investment_date')->nullable()->after('is_invested_master_guide');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('staff_master_guides')) {
            return;
        }

        Schema::table('staff_master_guides', function (Blueprint $table) {
            foreach ([
                'investment_date',
                'is_invested_master_guide',
                'previous_staff_where',
                'has_previous_staff_experience',
                'dob',
            ] as $column) {
                if (Schema::hasColumn('staff_master_guides', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
