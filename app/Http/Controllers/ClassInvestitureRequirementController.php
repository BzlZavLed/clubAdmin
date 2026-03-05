<?php

namespace App\Http\Controllers;

use App\Models\ClassInvestitureRequirement;
use App\Models\ClubClass;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class ClassInvestitureRequirementController extends Controller
{
    public function store(Request $request, ClubClass $clubClass)
    {
        $this->assertCanManageClass($request, $clubClass);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $sortOrder = $validated['sort_order'] ?? ((int) $clubClass->investitureRequirements()->max('sort_order') + 1);

        $requirement = $clubClass->investitureRequirements()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $sortOrder,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Requirement created successfully.',
            'requirement' => $requirement,
        ]);
    }

    public function update(Request $request, ClassInvestitureRequirement $investitureRequirement)
    {
        $this->assertCanManageRequirement($request, $investitureRequirement);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $investitureRequirement->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? $investitureRequirement->sort_order,
            'is_active' => $validated['is_active'] ?? $investitureRequirement->is_active,
        ]);

        return response()->json([
            'message' => 'Requirement updated successfully.',
            'requirement' => $investitureRequirement->fresh(),
        ]);
    }

    public function destroy(Request $request, ClassInvestitureRequirement $investitureRequirement)
    {
        $this->assertCanManageRequirement($request, $investitureRequirement);
        $investitureRequirement->delete();

        return response()->json([
            'message' => 'Requirement deleted successfully.',
        ]);
    }

    protected function assertCanManageRequirement(Request $request, ClassInvestitureRequirement $requirement): void
    {
        $requirement->loadMissing('clubClass');
        $this->assertCanManageClass($request, $requirement->clubClass);
    }

    protected function assertCanManageClass(Request $request, ClubClass $clubClass): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            return;
        }

        $clubIds = collect(ClubHelper::clubIdsForUser($user))
            ->map(fn ($id) => (int) $id)
            ->all();
        if (!in_array((int) $clubClass->club_id, $clubIds, true)) {
            abort(403);
        }
    }
}
