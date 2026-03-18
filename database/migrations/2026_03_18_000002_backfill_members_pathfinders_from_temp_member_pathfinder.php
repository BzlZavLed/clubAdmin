<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('members_pathfinders') || !Schema::hasTable('temp_member_pathfinder')) {
            return;
        }

        $legacyRows = DB::table('temp_member_pathfinder')
            ->orderBy('id')
            ->get();

        foreach ($legacyRows as $row) {
            $club = null;
            if (!empty($row->club_id) && Schema::hasTable('clubs')) {
                $club = DB::table('clubs')
                    ->where('id', $row->club_id)
                    ->first(['id', 'club_name', 'director_name', 'church_name']);
            }

            $existing = DB::table('members_pathfinders')
                ->where('source_temp_member_pathfinder_id', $row->id)
                ->first(['id', 'member_id']);

            $memberLink = DB::table('members')
                ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
                ->where(function ($query) use ($row, $existing) {
                    $query->where('id_data', $row->id);
                    if ($existing?->id) {
                        $query->orWhere('id_data', $existing->id);
                    }
                })
                ->orderBy('id')
                ->first(['id', 'club_id', 'id_data']);

            $payload = [
                'club_id' => $row->club_id ?: ($memberLink->club_id ?? null),
                'member_id' => $row->member_id ?: ($memberLink->id ?? null),
                'source_temp_member_pathfinder_id' => $row->id,
                'club_name' => $club->club_name ?? null,
                'director_name' => $club->director_name ?? null,
                'church_name' => $club->church_name ?? null,
                'applicant_name' => $row->nombre ?: 'Unknown Pathfinder',
                'birthdate' => $row->dob,
                'cell_number' => $row->phone,
                'email_address' => $row->email,
                'father_guardian_name' => $row->father_name,
                'father_guardian_phone' => $row->father_phone,
                'application_data' => json_encode([
                    'legacy_import' => [
                        'source_table' => 'temp_member_pathfinder',
                        'source_id' => $row->id,
                    ],
                ]),
                'status' => 'active',
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('members_pathfinders')
                    ->where('id', $existing->id)
                    ->update($payload);

                $memberPathfinderId = $existing->id;
            } else {
                $memberPathfinderId = DB::table('members_pathfinders')->insertGetId($payload);
            }

            DB::table('members')
                ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
                ->where('id_data', $row->id)
                ->update([
                    'id_data' => $memberPathfinderId,
                ]);

            if ($memberLink?->id) {
                DB::table('members_pathfinders')
                    ->where('id', $memberPathfinderId)
                    ->update([
                        'member_id' => $memberLink->id,
                    ]);

                if (Schema::hasColumn('temp_member_pathfinder', 'member_id')) {
                    DB::table('temp_member_pathfinder')
                        ->where('id', $row->id)
                        ->update([
                            'member_id' => $memberLink->id,
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        // This is a data backfill migration. Intentionally no destructive rollback.
    }
};
