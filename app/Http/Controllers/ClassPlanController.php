<?php

namespace App\Http\Controllers;

use App\Models\ClassPlan;
use App\Models\ClassInvestitureRequirement;
use App\Models\Staff;
use App\Models\WorkplanEvent;
use Illuminate\Http\Request;

class ClassPlanController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $staff = Staff::where('user_id', $user->id)->firstOrFail();

        $data = $this->validatePlan($request);
        $event = WorkplanEvent::with('workplan')->findOrFail($data['workplan_event_id']);

        $this->authorizeStaffClub($staff, $event);
        $this->assertRequirementBelongsToClass($data);

        $requiresApproval = array_key_exists('requires_approval', $data)
            ? (bool) $data['requires_approval']
            : ($data['type'] === 'outing' || (!empty($data['location_override']) && $data['location_override'] !== $event->location));

        $status = $requiresApproval ? 'submitted' : 'approved';

        $plan = ClassPlan::create([
            ...$data,
            'staff_id' => $staff->id,
            'class_id' => $data['class_id'] ?? null,
            'requires_approval' => $requiresApproval,
            'status' => $status,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Plan created',
            'plan' => $plan->load(['class', 'staff.user', 'investitureRequirement'])
        ]);
    }

    public function update(Request $request, ClassPlan $plan)
    {
        $user = $request->user();
        $staff = Staff::where('user_id', $user->id)->firstOrFail();
        $this->authorizeStaffClub($staff, $plan->event);

        if (in_array($plan->status, ['approved', 'rejected'])) {
            abort(403, 'Cannot modify an approved/rejected plan.');
        }

        $data = $this->validatePlan($request, false);
        $this->assertRequirementBelongsToClass($data, $plan);
        $requiresApproval = array_key_exists('requires_approval', $data)
            ? (bool) $data['requires_approval']
            : $plan->requires_approval;
        $status = $requiresApproval ? 'submitted' : 'approved';

        $plan->fill($data);
        $plan->requires_approval = $requiresApproval;
        $plan->status = $status;
        $plan->save();

        return response()->json(['message' => 'Plan updated', 'plan' => $plan->load(['class', 'staff.user', 'investitureRequirement'])]);
    }

    public function destroy(Request $request, ClassPlan $plan)
    {
        $user = $request->user();
        $staff = Staff::where('user_id', $user->id)->firstOrFail();
        $this->authorizeStaffClub($staff, $plan->event);
        if (in_array($plan->status, ['approved'])) {
            abort(403, 'Cannot delete an approved plan.');
        }
        $plan->delete();
        return response()->json(['message' => 'Plan deleted']);
    }

    public function updateStatus(Request $request, ClassPlan $plan)
    {
        $user = $request->user();
        if (!in_array($user->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403);
        }
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected,changes_requested',
            'request_note' => 'nullable|string|max:1000',
        ]);
        $plan->status = $validated['status'];
        if (array_key_exists('request_note', $validated)) {
            $plan->request_note = $validated['request_note'];
        }
        if (in_array($validated['status'], ['approved', 'rejected'])) {
            $plan->authorized_at = now();
        } else {
            $plan->authorized_at = null;
        }
        $plan->save();
        return response()->json(['message' => 'Status updated', 'plan' => $plan->load(['class', 'staff.user', 'investitureRequirement'])]);
    }

    private function validatePlan(Request $request, bool $requireEvent = true): array
    {
        return $request->validate([
            'workplan_event_id' => [$requireEvent ? 'required' : 'sometimes', 'exists:workplan_events,id'],
            'class_id' => ['nullable', 'exists:club_classes,id'],
            'investiture_requirement_id' => ['nullable', 'exists:class_investiture_requirements,id'],
            'type' => ['required', 'in:plan,outing'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requested_date' => ['nullable', 'date'],
            'location_override' => ['nullable', 'string', 'max:255'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);
    }

    private function assertRequirementBelongsToClass(array $data, ?ClassPlan $existingPlan = null): void
    {
        $requirementId = $data['investiture_requirement_id'] ?? null;
        if (!$requirementId) {
            return;
        }

        $classId = $data['class_id'] ?? $existingPlan?->class_id;
        if (!$classId) {
            abort(422, 'Select a class before linking an investiture requirement.');
        }

        $matchesClass = ClassInvestitureRequirement::query()
            ->where('id', $requirementId)
            ->where('club_class_id', $classId)
            ->exists();
        if (!$matchesClass) {
            abort(422, 'The selected requirement does not belong to the selected class.');
        }
    }

    private function authorizeStaffClub(Staff $staff, WorkplanEvent $event): void
    {
        if ($event->workplan->club_id !== $staff->club_id) {
            abort(403, 'Not allowed for this club.');
        }
    }
}
