<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_concepts') || !Schema::hasTable('payment_concept_scopes')) {
            return;
        }

        DB::table('payment_concepts')
            ->where('concept', 'Cuota de inscripción')
            ->whereNotNull('club_id')
            ->orderBy('id')
            ->get(['id', 'club_id'])
            ->each(function ($concept) {
                $scope = DB::table('payment_concept_scopes')
                    ->where('payment_concept_id', $concept->id)
                    ->where('scope_type', 'club_wide')
                    ->where('club_id', $concept->club_id)
                    ->whereNull('class_id')
                    ->whereNull('member_id')
                    ->whereNull('staff_id')
                    ->first();

                if ($scope) {
                    DB::table('payment_concept_scopes')
                        ->where('id', $scope->id)
                        ->update(['deleted_at' => null, 'updated_at' => now()]);
                    return;
                }

                DB::table('payment_concept_scopes')->insert([
                    'payment_concept_id' => $concept->id,
                    'scope_type' => 'club_wide',
                    'club_id' => $concept->club_id,
                    'class_id' => null,
                    'member_id' => null,
                    'staff_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
            });
    }

    public function down(): void
    {
        // The scope is valid domain data and may have been used after this migration.
    }
};
