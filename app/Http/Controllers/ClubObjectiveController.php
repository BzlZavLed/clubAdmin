<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubObjective;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class ClubObjectiveController extends Controller
{
    public function store(Request $request, Club $club)
    {
        $this->authorizeClub($request, $club);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'annual_evaluation_metric' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'min:0'],
            'external_objective_id' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $objective = $club->localObjectives()->create([
            'name' => trim($data['name']),
            'annual_evaluation_metric' => isset($data['annual_evaluation_metric']) ? trim((string) $data['annual_evaluation_metric']) ?: null : null,
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'department_id' => $data['department_id'] ?? null,
            'external_objective_id' => $data['external_objective_id'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $objective]);
    }

    public function update(Request $request, Club $club, ClubObjective $objective)
    {
        $this->authorizeClub($request, $club);
        abort_if((int) $objective->club_id !== (int) $club->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'annual_evaluation_metric' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'min:0'],
            'external_objective_id' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $objective->update([
            'name' => trim($data['name']),
            'annual_evaluation_metric' => isset($data['annual_evaluation_metric']) ? trim((string) $data['annual_evaluation_metric']) ?: null : null,
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'department_id' => $data['department_id'] ?? null,
            'external_objective_id' => $data['external_objective_id'] ?? null,
            'status' => $data['status'] ?? $objective->status,
        ]);

        return response()->json(['data' => $objective->fresh()]);
    }

    public function destroy(Request $request, Club $club, ClubObjective $objective)
    {
        $this->authorizeClub($request, $club);
        abort_if((int) $objective->club_id !== (int) $club->id, 404);

        $objective->delete();

        return response()->json(['status' => 'deleted']);
    }

    protected function authorizeClub(Request $request, Club $club): void
    {
        $clubIds = ClubHelper::clubIdsForUser($request->user())->all();
        abort_unless(in_array((int) $club->id, $clubIds, true), 403, 'Not allowed for this club.');
    }
}
