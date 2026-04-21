<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('members_adventurers', 'insurance_paid')) {
            Schema::table('members_adventurers', function (Blueprint $table) {
                $table->boolean('insurance_paid')->default(false);
            });
        }

        if (!Schema::hasColumn('members_adventurers', 'insurance_paid_at')) {
            Schema::table('members_adventurers', function (Blueprint $table) {
                $table->timestamp('insurance_paid_at')->nullable();
            });
        }

        if (!Schema::hasColumn('members_adventurers', 'enrollment_paid')) {
            Schema::table('members_adventurers', function (Blueprint $table) {
                $table->boolean('enrollment_paid')->default(false);
            });
        }

        if (!Schema::hasColumn('members_adventurers', 'enrollment_paid_at')) {
            Schema::table('members_adventurers', function (Blueprint $table) {
                $table->timestamp('enrollment_paid_at')->nullable();
            });
        }

        if (!Schema::hasColumn('members_pathfinders', 'insurance_paid')) {
            Schema::table('members_pathfinders', function (Blueprint $table) {
                $table->boolean('insurance_paid')->default(false);
            });
        }

        if (!Schema::hasColumn('members_pathfinders', 'insurance_paid_at')) {
            Schema::table('members_pathfinders', function (Blueprint $table) {
                $table->timestamp('insurance_paid_at')->nullable();
            });
        }

        if (!Schema::hasColumn('members_pathfinders', 'enrollment_paid')) {
            Schema::table('members_pathfinders', function (Blueprint $table) {
                $table->boolean('enrollment_paid')->default(false);
            });
        }

        if (!Schema::hasColumn('members_pathfinders', 'enrollment_paid_at')) {
            Schema::table('members_pathfinders', function (Blueprint $table) {
                $table->timestamp('enrollment_paid_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $adventurerColumns = collect(['enrollment_paid', 'enrollment_paid_at'])
            ->filter(fn ($column) => Schema::hasColumn('members_adventurers', $column))
            ->values()
            ->all();

        if (!empty($adventurerColumns)) {
            Schema::table('members_adventurers', function (Blueprint $table) use ($adventurerColumns) {
                $table->dropColumn($adventurerColumns);
            });
        }

        $pathfinderColumns = collect(['enrollment_paid', 'enrollment_paid_at'])
            ->filter(fn ($column) => Schema::hasColumn('members_pathfinders', $column))
            ->values()
            ->all();

        if (!empty($pathfinderColumns)) {
            Schema::table('members_pathfinders', function (Blueprint $table) use ($pathfinderColumns) {
                $table->dropColumn($pathfinderColumns);
            });
        }
    }
};
