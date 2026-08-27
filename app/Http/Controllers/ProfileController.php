<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Member;
use App\Services\ParentFamilyDataDeletionService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\User;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        abort_if(
            $request->user()->profile_type === 'parent' && ! $request->user()->canSelfServiceCredentials(),
            403,
            'Ask the club director to update this account.'
        );

        $component = $request->user()->profile_type === 'parent'
            ? 'Parent/Profile'
            : 'Profile/Edit';

        return Inertia::render($component, [
            'auth_user' => $request->user(),
            'account_church_name' => $request->user()->church?->church_name ?: $request->user()->church_name,
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'related_churches' => $request->user()->profile_type === 'parent'
                ? $this->relatedChurches($request->user())
                : [],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        abort_if(
            $request->user()->profile_type === 'parent' && ! $request->user()->canSelfServiceCredentials(),
            403,
            'Ask the club director to update this account.'
        );

        $request->user()->fill($request->validated());

        $emailChanged = $request->user()->isDirty('email');
        if ($emailChanged) {
            $request->user()->email_verified_at = null;
            if ($request->user()->profile_type === 'parent') {
                $request->user()->parent_activation_method = null;
                $request->user()->enrollment_confirmed_at = null;
                $request->user()->enrollment_confirmed_by = null;
            }
        }

        $request->user()->save();

        if ($emailChanged && $request->user()->profile_type === 'parent') {
            try {
                $request->user()->sendEmailVerificationNotification();
                $status = 'verification-link-sent';
            } catch (Throwable $exception) {
                report($exception);
                $status = 'verification-delivery-failed';
            }

            return Redirect::route('verification.notice')->with('status', $status);
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort_if(
            $request->user()->profile_type === 'parent',
            403,
            'Parent accounts must use the two-stage family data deletion process.'
        );

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function destroyFamilyData(Request $request, ParentFamilyDataDeletionService $deletionService): JsonResponse
    {
        abort_unless($request->user()->profile_type === 'parent', 403);

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:DELETE MY CHILDREN'],
        ]);

        $deleted = $deletionService->deleteChildrenFor($request->user());
        $token = Str::random(64);

        $request->session()->put('parent_account_deletion', [
            'token_hash' => Hash::make($token),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        return response()->json([
            'message' => "{$deleted} child record(s) were permanently deleted.",
            'children_deleted' => $deleted,
            'account_deletion_token' => $token,
        ]);
    }

    public function destroyParentAccount(Request $request, ParentFamilyDataDeletionService $deletionService): JsonResponse
    {
        abort_unless($request->user()->profile_type === 'parent', 403);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:DELETE MY ACCOUNT'],
            'deletion_token' => ['required', 'string'],
        ]);

        $authorization = $request->session()->get('parent_account_deletion');
        if (
            ! is_array($authorization)
            || ($authorization['expires_at'] ?? 0) < now()->timestamp
            || ! Hash::check($validated['deletion_token'], $authorization['token_hash'] ?? '')
        ) {
            throw ValidationException::withMessages([
                'deletion_token' => 'The account deletion confirmation expired. Delete family data again to continue.',
            ]);
        }

        if (Member::query()->where('parent_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages([
                'deletion_token' => 'Child records must be deleted before the parent account.',
            ]);
        }

        $user = $request->user();
        Auth::logout();
        $deletionService->deleteAccount($user);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'The parent account was permanently deleted.',
            'redirect_url' => route('login'),
        ]);
    }

    private function relatedChurches(User $parent): array
    {
        return Member::query()
            ->with(['club.church'])
            ->where('parent_id', $parent->id)
            ->where('status', '!=', 'deleted')
            ->get()
            ->filter(fn (Member $member) => $member->club && (int) $member->club->church_id !== (int) $parent->church_id)
            ->groupBy('club_id')
            ->map(function ($members): array {
                $club = $members->first()->club;

                return [
                    'church_name' => $club->church?->church_name ?: $club->church_name,
                    'club_name' => $club->club_name,
                    'club_type' => $club->club_type,
                    'children_count' => $members->count(),
                    'related_since' => $members->min('created_at')?->toDateString(),
                ];
            })
            ->sortBy([['church_name', 'asc'], ['club_name', 'asc']])
            ->values()
            ->all();
    }
    
}
