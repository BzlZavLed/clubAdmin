<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubIntegrationConfig;
use App\Models\ChurchInviteCode;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\Staff;
use App\Models\StaffAdventurer;
use App\Models\StaffMasterGuide;
use App\Models\StaffPathfinder;
use App\Models\User;
use App\Services\ClubLogoService;
use App\Support\ClubHelper;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Log;

class ClubSettingsController extends Controller
{
    public function index(Request $request, ClubLogoService $clubLogoService)
    {
        $user = $request->user();
        $clubIds = ClubHelper::clubIdsForUser($user);
        $clubs = Club::whereIn('id', $clubIds)->orderBy('club_name')->get(['id', 'club_name', 'church_id', 'church_name', 'logo_path', 'club_email']);
        $selectedClubId = $this->resolveSelectedClubId($request, $clubs);

        if ($selectedClubId) {
            $selectedClub = $clubs->firstWhere('id', (int) $selectedClubId);
            $request->session()->put('club_context.club_id', $selectedClub->id);
        }

        $config = $selectedClubId
            ? ClubIntegrationConfig::where('club_id', $selectedClubId)->first()
            : null;

        return Inertia::render('ClubDirector/Settings', [
            'auth_user' => $user,
            'clubs' => $clubs,
            'selected_club_id' => $selectedClubId,
            'integration_config' => $config,
            'club_logo_url' => $clubLogoService->url($clubs->firstWhere('id', (int) $selectedClubId)),
            'selected_club' => $selectedClubId
                ? $clubs->firstWhere('id', (int) $selectedClubId)?->only(['id', 'club_name', 'church_id', 'club_email'])
                : null,
            'enrollment_session' => $selectedClubId
                ? $this->enrollmentSessionPayload($clubs->firstWhere('id', (int) $selectedClubId), $user)
                : null,
        ]);
    }

    public function enrollmentSession(Request $request)
    {
        $payload = $request->validate(['club_id' => ['required', 'integer']]);
        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);

        return response()->json([
            'data' => $this->enrollmentSessionPayload($club, $request->user()),
        ]);
    }

    public function enrollmentSessionPage(Request $request)
    {
        $user = $request->user();
        $clubs = Club::whereIn('id', ClubHelper::clubIdsForUser($user))
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'church_id']);
        $selectedClubId = $this->resolveSelectedClubId($request, $clubs);

        return Inertia::render('ClubDirector/EnrollmentSession', [
            'auth_user' => $user,
            'clubs' => $clubs,
            'selected_club_id' => $selectedClubId,
            'enrollment_session' => $selectedClubId
                ? $this->enrollmentSessionPayload($clubs->firstWhere('id', (int) $selectedClubId), $user)
                : null,
        ]);
    }

    public function approveEnrollmentParent(Request $request, User $user)
    {
        $payload = $request->validate(['club_id' => ['required', 'integer']]);
        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $this->assertPendingParentForClub($user, $club);

        DB::transaction(function () use ($user, $club) {
            $user->update(['status' => 'active']);
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $club->id],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
        });

        return response()->json(['data' => $this->enrollmentSessionPayload($club, $request->user())]);
    }

    public function rejectEnrollmentParent(Request $request, User $user)
    {
        $payload = $request->validate(['club_id' => ['required', 'integer']]);
        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $this->assertPendingParentForClub($user, $club);

        DB::transaction(function () use ($user, $club) {
            $user->update(['status' => 'rejected']);
            DB::table('club_user')
                ->where('user_id', $user->id)
                ->where('club_id', $club->id)
                ->update(['status' => 'rejected', 'updated_at' => now()]);
        });

        return response()->json(['data' => $this->enrollmentSessionPayload($club, $request->user())]);
    }

    public function approveEnrollmentStaff(Request $request, User $user)
    {
        $payload = $request->validate([
            'club_id' => ['required', 'integer'],
            'assigned_class' => ['nullable', 'integer'],
            'make_treasurer' => ['nullable', 'boolean'],
        ]);
        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $this->assertPendingStaffForClub($user, $club);

        if (!empty($payload['assigned_class'])) {
            ClubClass::query()
                ->where('id', $payload['assigned_class'])
                ->where('club_id', $club->id)
                ->firstOrFail();
        }

        DB::transaction(function () use ($user, $club, $payload) {
            $staff = $this->createEnrollmentStaffRecord($user, $club);

            if (!empty($payload['assigned_class'])) {
                $staff->update(['assigned_class' => $payload['assigned_class']]);
                $staff->classes()->sync([$payload['assigned_class'] => ['club_id' => $club->id]]);
            }

            $user->forceFill([
                'status' => 'active',
                'profile_type' => !empty($payload['make_treasurer']) ? 'treasurer' : 'club_personal',
                'role_key' => !empty($payload['make_treasurer']) ? 'treasurer' : 'club_personal',
                'scope_type' => 'club',
                'scope_id' => $club->id,
                'club_id' => $club->id,
            ])->save();
            $staff->update(['status' => 'active']);
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $club->id],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
        });

        return response()->json(['data' => $this->enrollmentSessionPayload($club, $request->user())]);
    }

    public function rejectEnrollmentStaff(Request $request, User $user)
    {
        $payload = $request->validate(['club_id' => ['required', 'integer']]);
        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $this->assertPendingStaffForClub($user, $club);

        DB::transaction(function () use ($user, $club) {
            $user->update(['status' => 'rejected']);
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $club->id],
                ['status' => 'rejected', 'created_at' => now(), 'updated_at' => now()]
            );
        });

        return response()->json(['data' => $this->enrollmentSessionPayload($club, $request->user())]);
    }

    public function enrollmentQr(Request $request, Club $club)
    {
        $this->resolveAllowedClub($request, (int) $club->id);

        $registrationUrl = $request->query('kind') === 'staff'
            ? route('register', ['profile_type' => 'club_personal', 'club_id' => $club->id])
            : route('parent.register');
        $qrCode = new QrCode(
            $registrationUrl,
            size: 520,
            margin: 16,
        );
        $result = (new PngWriter())->write($qrCode);

        return response($result->getString(), 200, ['Content-Type' => $result->getMimeType()]);
    }

    public function updateContact(Request $request)
    {
        $payload = $request->validate([
            'club_id' => ['required', 'integer'],
            'club_email' => ['nullable', 'email', 'max:255'],
        ]);

        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $club->forceFill([
            'club_email' => $payload['club_email'] ?? null,
        ])->save();

        return response()->json([
            'status' => 'ok',
            'club' => $club->only(['id', 'club_name', 'club_email']),
        ]);
    }

    public function uploadLogo(Request $request, ClubLogoService $clubLogoService)
    {
        $payload = $request->validate([
            'club_id' => ['required', 'integer'],
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $oldPath = $club->logo_path;
        $path = $request->file('logo')->store("club-logos/{$club->id}", 'public');

        $club->forceFill(['logo_path' => $path])->save();

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'status' => 'ok',
            'logo_url' => $clubLogoService->url($club),
            'club' => $club->only(['id', 'club_name', 'logo_path']),
        ]);
    }

    public function removeLogo(Request $request)
    {
        $payload = $request->validate([
            'club_id' => ['required', 'integer'],
        ]);

        $club = $this->resolveAllowedClub($request, (int) $payload['club_id']);
        $oldPath = $club->logo_path;
        $club->forceFill(['logo_path' => null])->save();

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'status' => 'ok',
            'logo_url' => null,
            'club' => $club->only(['id', 'club_name', 'logo_path']),
        ]);
    }

    public function fetchCatalog(Request $request)
    {
        $payload = $request->validate([
            'invite_code' => ['required', 'string'],
            'club_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $clubId = $payload['club_id'] ?: $request->session()->get('club_context.club_id') ?: $user->club_id;
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->all();
        if ($clubId && !in_array($clubId, $allowedClubIds)) {
            abort(403, 'Not allowed to fetch catalog for this club.');
        }

        $baseUrl = rtrim(config('services.mychurchadmin.base_url'), '/');
        $token = config('services.mychurchadmin.token');
        if (!$baseUrl) {
            abort(422, 'Missing mychurchadmin base URL.');
        }
        if (!$token) {
            abort(422, 'Missing integration token.');
        }

        $url = $baseUrl . '/api/integrations/clubs/catalog';
        try {
            $response = Http::withHeaders([
                    'X-Integration-Token' => $token,
                ])
                ->acceptJson()
                ->timeout(20)
                ->get($url, ['invite_code' => $payload['invite_code']]);
        } catch (\Throwable $e) {
            Log::warning('Catalog fetch failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Catalog request failed.'], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Catalog fetch failed.',
                'status' => $response->status(),
                'error' => $response->json() ?? $response->body(),
            ], $response->status());
        }

        return response()->json($response->json());
    }

    private function enrollmentSessionPayload(Club $club, User $actor): array
    {
        $invite = ChurchInviteCode::firstOrCreate(
            ['church_id' => $club->church_id],
            [
                'code' => ChurchInviteCode::generateCode(),
                'status' => 'active',
                'created_by' => $actor->id,
            ]
        );

        $parents = User::query()
            ->where('profile_type', 'parent')
            ->where('club_id', $club->id)
            ->whereIn('status', ['pending', 'active'])
            ->orderBy('created_at')
            ->get(['id', 'name', 'email', 'status', 'created_at']);
        $staffRequests = User::query()
            ->where('profile_type', 'club_personal')
            ->where('club_id', $club->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get(['id', 'name', 'email', 'sub_role', 'created_at']);
        $childrenByParent = Member::query()
            ->where('club_id', $club->id)
            ->whereIn('parent_id', $parents->pluck('id'))
            ->with(['club:id,club_name', 'class:id,class_name'])
            ->get()
            ->groupBy('parent_id');

        $formatParent = function (User $parent) use ($childrenByParent): array {
            return [
                'id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
                'status' => $parent->status,
                'requested_at' => $parent->created_at?->toIso8601String(),
                'children' => ($childrenByParent->get($parent->id, collect()))
                    ->map(function (Member $member) {
                        $detail = ClubHelper::memberDetail($member);

                        return [
                            'id' => $member->id,
                            'name' => $detail['name'] ?? '—',
                            'age' => $detail['age'] ?? null,
                            'club_name' => $member->club?->club_name,
                            'class_name' => $member->class?->class_name,
                            'can_assign_class' => in_array($member->type, ['adventurers', 'pathfinders', 'temp_pathfinder'], true),
                        ];
                    })
                    ->values(),
            ];
        };

        return [
            'club' => $club->only(['id', 'club_name', 'church_id']),
            'registration_url' => route('parent.register'),
            'qr_url' => route('club.settings.enrollment.qr', ['club' => $club->id]),
            'staff_registration_url' => route('register', ['profile_type' => 'club_personal', 'club_id' => $club->id]),
            'staff_qr_url' => route('club.settings.enrollment.qr', ['club' => $club->id, 'kind' => 'staff']),
            'church_invite_code' => $invite->code,
            'pending_parents' => $parents->where('status', 'pending')->map($formatParent)->values(),
            'pending_staff' => $staffRequests->map(fn (User $staff) => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'sub_role' => $staff->sub_role,
                'requested_at' => $staff->created_at?->toIso8601String(),
            ])->values(),
            'classes' => ClubClass::query()
                ->where('club_id', $club->id)
                ->orderBy('class_name')
                ->get(['id', 'class_name'])
                ->map(fn (ClubClass $class) => ['id' => $class->id, 'name' => $class->class_name])
                ->values(),
            'enrolled_parents' => $parents
                ->where('status', 'active')
                ->map($formatParent)
                ->filter(fn (array $parent) => !empty($parent['children']))
                ->values(),
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function assertPendingParentForClub(User $user, Club $club): void
    {
        abort_unless(
            $user->profile_type === 'parent'
                && (int) $user->club_id === (int) $club->id
                && $user->status === 'pending',
            422,
            'This parent request is no longer pending for the selected club.'
        );
    }

    private function assertPendingStaffForClub(User $user, Club $club): void
    {
        abort_unless(
            $user->profile_type === 'club_personal'
                && (int) $user->club_id === (int) $club->id
                && $user->status === 'pending',
            422,
            'This staff request is no longer pending for the selected club.'
        );
    }

    private function createEnrollmentStaffRecord(User $user, Club $club): Staff
    {
        $existing = Staff::query()
            ->where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($club->club_type === 'master_guide') {
            $detail = StaffMasterGuide::create([
                'club_id' => $club->id,
                'user_id' => $user->id,
                'staff_name' => $user->name,
                'email' => $user->email,
                'status' => 'pending',
            ]);
            $staff = Staff::create([
                'type' => 'master_guide',
                'id_data' => $detail->id,
                'club_id' => $club->id,
                'user_id' => $user->id,
                'status' => 'pending',
            ]);
            $detail->update(['staff_id' => $staff->id]);
            return $staff;
        }

        if (in_array($club->club_type, ['pathfinders', 'temp_pathfinder'], true)) {
            $detail = StaffPathfinder::create([
                'club_id' => $club->id,
                'user_id' => $user->id,
                'staff_name' => $user->name,
                'staff_email' => $user->email,
            ]);
            $staff = Staff::create([
                'type' => 'pathfinders',
                'id_data' => $detail->id,
                'club_id' => $club->id,
                'user_id' => $user->id,
                'status' => 'pending',
            ]);
            $detail->update(['staff_id' => $staff->id]);
            return $staff;
        }

        $detail = StaffAdventurer::create([
            'name' => $user->name,
            'email' => $user->email,
            'club_id' => $club->id,
            'church_name' => $club->church_name,
            'club_name' => $club->club_name,
            'applicant_signature' => $user->name,
            'status' => 'pending',
        ]);

        return Staff::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    private function resolveAllowedClub(Request $request, int $clubId): Club
    {
        $allowedClubIds = ClubHelper::clubIdsForUser($request->user())->map(fn ($id) => (int) $id)->all();
        if (!in_array($clubId, $allowedClubIds, true)) {
            abort(403, 'Not allowed to manage settings for this club.');
        }

        return Club::withoutGlobalScopes()->findOrFail($clubId);
    }

    public function saveConfig(Request $request)
    {
        $payload = $request->validate([
            'invite_code' => ['required', 'string'],
            'club_id' => ['nullable', 'integer'],
            'catalog' => ['required', 'array'],
            'catalog.status' => ['nullable', 'string'],
            'catalog.church' => ['nullable', 'array'],
            'catalog.church.id' => ['nullable', 'integer'],
            'catalog.church.name' => ['nullable', 'string'],
            'catalog.church.slug' => ['nullable', 'string'],
            'catalog.church_slug' => ['nullable', 'string'],
            'catalog.departments' => ['nullable', 'array'],
            'catalog.objectives' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $clubId = $payload['club_id'] ?: $request->session()->get('club_context.club_id') ?: $user->club_id;
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->all();
        if ($clubId && !in_array($clubId, $allowedClubIds)) {
            abort(403, 'Not allowed to save settings for this club.');
        }

        $catalog = $payload['catalog'];
        $church = $catalog['church'] ?? [];
        $config = ClubIntegrationConfig::updateOrCreate(
            ['club_id' => $clubId],
            [
                'invite_code' => $payload['invite_code'],
                'status' => $catalog['status'] ?? null,
                'church_id' => $church['id'] ?? null,
                'church_name' => $church['name'] ?? null,
                'church_slug' => $catalog['church_slug'] ?? ($church['slug'] ?? null),
                'departments' => $catalog['departments'] ?? [],
                'objectives' => $catalog['objectives'] ?? [],
                'fetched_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'config' => $config,
        ]);
    }

    private function resolveSelectedClubId(Request $request, $clubs): ?int
    {
        $candidates = collect([
            $request->input('club_id'),
            $request->session()->get('club_context.club_id'),
            $request->user()?->club_id,
            $clubs->first()->id ?? null,
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        foreach ($candidates as $candidate) {
            if ($clubs->contains('id', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
