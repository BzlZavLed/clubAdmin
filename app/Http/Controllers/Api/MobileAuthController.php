<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\User;
use App\Models\WorkplanTaskAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['mobileMember.club', 'mobileMember.class'])
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$this->canUseMobile($user)) {
            throw ValidationException::withMessages([
                'email' => ['This account is not enabled for mobile access.'],
            ]);
        }

        $user->tokens()->where('name', 'mobile')->delete();
        $token = $user->createToken('mobile', ['mobile'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user->fresh(['mobileMember.club', 'mobileMember.class'])),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()->load(['mobileMember.club', 'mobileMember.class'])),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function tasks(Request $request)
    {
        $user = $request->user()->load('mobileMember');
        $member = $user->mobileMember;

        if (!$member) {
            return response()->json(['data' => []]);
        }

        $assignments = WorkplanTaskAssignment::query()
            ->with(['task.event', 'task.classPlan', 'latestSubmission'])
            ->where('member_id', $member->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (WorkplanTaskAssignment $assignment) => [
                'id' => $assignment->id,
                'title' => $assignment->task?->title,
                'status' => $assignment->status,
                'due_at' => optional($assignment->task?->due_at)->toIso8601String(),
                'assigned_at' => optional($assignment->assigned_at)->toIso8601String(),
                'submitted_at' => optional($assignment->submitted_at)->toIso8601String(),
                'completed_at' => optional($assignment->completed_at)->toIso8601String(),
                'latest_submission_status' => $assignment->latestSubmission?->status,
                'task' => [
                    'id' => $assignment->task?->id,
                    'title' => $assignment->task?->title,
                    'description' => $assignment->task?->description,
                    'task_type' => $assignment->task?->task_type,
                    'review_mode' => $assignment->task?->review_mode,
                    'points' => $assignment->task?->points,
                    'due_at' => optional($assignment->task?->due_at)->toIso8601String(),
                    'event_title' => $assignment->task?->event?->title,
                    'event_date' => optional($assignment->task?->event?->date)->toDateString(),
                    'class_plan_title' => $assignment->task?->classPlan?->title,
                ],
            ]);

        return response()->json(['data' => $assignments]);
    }

    public function locationSession()
    {
        return response()->json([
            'data' => null,
            'message' => 'Location safety backend frame is installed; active tracking sessions are not wired yet.',
        ]);
    }

    private function canUseMobile(User $user): bool
    {
        if ($user->status && $user->status !== 'active') {
            return false;
        }

        if ($user->profile_type === 'parent') {
            return true;
        }

        if ($user->profile_type !== 'member') {
            return false;
        }

        $member = $user->mobileMember;

        return $member instanceof Member
            && in_array($member->type, ['pathfinders', 'temp_pathfinder', 'master_guides'], true)
            && $member->status !== 'deleted';
    }

    private function userPayload(User $user): array
    {
        $member = $user->mobileMember;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_type' => $user->profile_type,
            'club_id' => $user->club_id,
            'mobile_member' => $member ? [
                'id' => $member->id,
                'type' => $member->type,
                'club_id' => $member->club_id,
                'club_name' => $member->club?->club_name,
                'class_id' => $member->class_id,
                'class_name' => $member->class?->class_name,
                'status' => $member->status,
            ] : null,
        ];
    }
}
