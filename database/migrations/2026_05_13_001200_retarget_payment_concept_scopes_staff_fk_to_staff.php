<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('payment_concept_scopes')
            || !Schema::hasColumn('payment_concept_scopes', 'staff_id')
            || !Schema::hasTable('staff')
        ) {
            return;
        }

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            try {
                $table->dropForeign(['staff_id']);
            } catch (\Throwable $e) {
                // Ignore when the legacy FK does not exist on this connection.
            }
        });

        DB::table('payment_concept_scopes')
            ->whereNotNull('staff_id')
            ->orderBy('id')
            ->get(['id', 'staff_id'])
            ->each(function ($scope) {
                $currentId = (int) $scope->staff_id;

                if (DB::table('staff')->where('id', $currentId)->exists()) {
                    return;
                }

                $staffId = DB::table('staff')
                    ->where('id_data', $currentId)
                    ->value('id');

                DB::table('payment_concept_scopes')
                    ->where('id', $scope->id)
                    ->update(['staff_id' => $staffId ?: null]);
            });

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            $table->foreign('staff_id')
                ->references('id')
                ->on('staff')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('payment_concept_scopes')
            || !Schema::hasColumn('payment_concept_scopes', 'staff_id')
            || !Schema::hasTable('staff_adventurers')
        ) {
            return;
        }

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            try {
                $table->dropForeign(['staff_id']);
            } catch (\Throwable $e) {
                // Ignore when the FK is already absent.
            }
        });

        DB::table('payment_concept_scopes')
            ->whereNotNull('staff_id')
            ->orderBy('id')
            ->get(['id', 'staff_id'])
            ->each(function ($scope) {
                $currentId = (int) $scope->staff_id;

                if (DB::table('staff_adventurers')->where('id', $currentId)->exists()) {
                    return;
                }

                $legacyStaffId = DB::table('staff')
                    ->where('id', $currentId)
                    ->value('id_data');

                DB::table('payment_concept_scopes')
                    ->where('id', $scope->id)
                    ->update(['staff_id' => $legacyStaffId ?: null]);
            });

        Schema::table('payment_concept_scopes', function (Blueprint $table) {
            $table->foreign('staff_id')
                ->references('id')
                ->on('staff_adventurers')
                ->nullOnDelete();
        });
    }
};
