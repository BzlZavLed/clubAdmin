<?php

namespace App\Http\Controllers;

use App\Models\ClassPlan;
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

        return response()->json(['message' => 'Plan created', 'plan' => $plan]);
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
        $requiresApproval = array_key_exists('requires_approval', $data)
            ? (bool) $data['requires_approval']
            : $plan->requires_approval;
        $status = $requiresApproval ? 'submitted' : 'approved';

        $plan->fill($data);
        $plan->requires_approval = $requiresApproval;
        $plan->status = $status;
        $plan->save();

        return response()->json(['message' => 'Plan updated', 'plan' => $plan]);
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
        if ($user->profile_type !== 'club_director') {
            abort(403);
        }
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);
        $plan->status = $validated['status'];
        $plan->save();
        return response()->json(['message' => 'Status updated', 'plan' => $plan]);
    }

    private function validatePlan(Request $request, bool $requireEvent = true): array
    {
        return $request->validate([
            'workplan_event_id' => [$requireEvent ? 'required' : 'sometimes', 'exists:workplan_events,id'],
            'class_id' => ['nullable', 'exists:club_classes,id'],
            'type' => ['required', 'in:plan,outing'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requested_date' => ['nullable', 'date'],
            'location_override' => ['nullable', 'string', 'max:255'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeStaffClub(Staff $staff, WorkplanEvent $event): void
    {
        if ($event->workplan->club_id !== $staff->club_id) {
            abort(403, 'Not allowed for this club.');
        }
    }
}
