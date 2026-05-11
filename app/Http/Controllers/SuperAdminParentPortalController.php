<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\ParentMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminParentPortalController extends Controller
{
    public function openExisting(Request $request, User $parent)
    {
        abort_unless($parent->profile_type === 'parent' && $parent->status !== 'deleted', 404);

        $this->startExistingParentPreview($request, $parent);

        return redirect()->route('parent.dashboard');
    }

    public function openMember(Request $request, Member $member)
    {
        abort_unless($this->hasParentContact($member), 404);

        if ($member->parent_id) {
            $parent = User::query()
                ->whereKey($member->parent_id)
                ->where('profile_type', 'parent')
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))
                ->first();

            if ($parent) {
                $this->startExistingParentPreview($request, $parent);

                return redirect()->route('parent.dashboard');
            }
        }

        $contact = $this->parentContact($member);
        $contactEmail = Str::lower(trim((string) ($contact['parent_email'] ?? '')));
        if ($contactEmail !== '') {
            $parent = User::query()
                ->where('email', $contactEmail)
                ->where('profile_type', 'parent')
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))
                ->first();

            if ($parent) {
                $member->forceFill(['parent_id' => $parent->id])->save();
                $this->syncLegacyParentMemberLink($parent, $member, $contact);
                $this->startExistingParentPreview($request, $parent);

                return redirect()->route('parent.dashboard');
            }
        }

        $request->session()->forget('superadmin_parent_portal_user_id');
        $request->session()->put('superadmin_parent_portal_member_id', $member->id);
        $request->session()->put('superadmin_parent_portal_actor_id', $request->user()->id);

        return redirect()->route('parent.dashboard');
    }

    public function createAccount(Request $request, Member $member)
    {
        abort_unless($request->user()?->profile_type === 'superadmin', 403);

        if ($member->parent_id) {
            $parent = User::query()
                ->whereKey($member->parent_id)
                ->where('profile_type', 'parent')
                ->firstOrFail();

            $this->startExistingParentPreview($request, $parent);

            return response()->json([
                'created' => false,
                'parent_user_id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
                'temporary_password' => null,
                'portal_url' => route('parent.dashboard'),
                'message' => 'El miembro ya estaba vinculado a una cuenta de padre.',
            ]);
        }

        $contact = $this->parentContact($member);
        $parentName = trim((string) ($contact['parent_name'] ?? ''));
        $parentEmail = Str::lower(trim((string) ($contact['parent_email'] ?? '')));

        if ($parentName === '') {
            return response()->json([
                'message' => 'Este miembro no tiene nombre de padre/madre registrado.',
            ], 422);
        }

        if ($parentEmail === '') {
            return response()->json([
                'message' => 'Este miembro tiene nombre de padre/madre, pero no tiene correo registrado. Agrega un correo antes de crear la cuenta.',
            ], 422);
        }

        $temporaryPassword = null;
        $created = false;

        $parent = DB::transaction(function () use ($member, $contact, $parentName, $parentEmail, &$temporaryPassword, &$created) {
            $existing = User::query()->where('email', $parentEmail)->first();

            if ($existing && $existing->profile_type !== 'parent') {
                abort(422, 'Ya existe un usuario con este correo, pero no es una cuenta de padre.');
            }

            if ($existing && $existing->status && $existing->status !== 'active') {
                abort(422, 'Ya existe una cuenta de padre con este correo, pero no esta activa.');
            }

            if ($existing) {
                $parent = $existing;
                $parent->forceFill([
                    'role_key' => $parent->role_key ?: 'parent',
                    'scope_type' => $parent->scope_type ?: 'user',
                    'scope_id' => $parent->scope_id ?: $parent->id,
                    'church_id' => $parent->church_id ?: ($contact['church_id'] ?? null),
                    'church_name' => $parent->church_name ?: ($contact['church_name'] ?? null),
                    'club_id' => $parent->club_id ?: ($contact['club_id'] ?? null),
                ])->save();
            } else {
                $temporaryPassword = 'PADRE-' . Str::upper(Str::random(6));
                $created = true;

                $parent = User::create([
                    'name' => $parentName,
                    'email' => $parentEmail,
                    'password' => Hash::make($temporaryPassword),
                    'profile_type' => 'parent',
                    'role_key' => 'parent',
                    'scope_type' => 'user',
                    'church_id' => $contact['church_id'] ?? null,
                    'church_name' => $contact['church_name'] ?? null,
                    'club_id' => $contact['club_id'] ?? null,
                    'status' => 'active',
                    'must_change_password' => true,
                ]);

                $parent->scope_id = $parent->id;
                $parent->save();
            }

            $member->forceFill(['parent_id' => $parent->id])->save();
            $this->syncLegacyParentMemberLink($parent, $member, $contact);

            return $parent;
        });

        $this->startExistingParentPreview($request, $parent);

        return response()->json([
            'created' => $created,
            'parent_user_id' => $parent->id,
            'name' => $parent->name,
            'email' => $parent->email,
            'temporary_password' => $temporaryPassword,
            'portal_url' => route('parent.dashboard'),
            'message' => $created
                ? 'Cuenta de padre creada y vinculada al miembro.'
                : 'Cuenta de padre existente vinculada al miembro.',
        ]);
    }

    public static function dashboardSetupPayload(Request $request): ?array
    {
        if ($request->user()?->profile_type !== 'superadmin') {
            return null;
        }

        $memberId = $request->session()->get('superadmin_parent_portal_member_id');
        if (!$memberId) {
            return null;
        }

        $member = Member::with(['club.church'])
            ->whereKey($memberId)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))
            ->first();

        if (!$member || $member->parent_id) {
            $request->session()->forget('superadmin_parent_portal_member_id');
            return null;
        }

        $controller = new self();
        $contact = $controller->parentContact($member);

        return [
            'needs_account' => true,
            'member_id' => $member->id,
            'member_name' => $contact['member_name'] ?? null,
            'parent_name' => $contact['parent_name'] ?? null,
            'parent_email' => $contact['parent_email'] ?? null,
            'parent_phone' => $contact['parent_phone'] ?? null,
            'club_name' => $contact['club_name'] ?? null,
            'church_name' => $contact['church_name'] ?? null,
            'can_create' => filled($contact['parent_name'] ?? null) && filled($contact['parent_email'] ?? null),
        ];
    }

    protected function startExistingParentPreview(Request $request, User $parent): void
    {
        $request->session()->forget('superadmin_parent_portal_member_id');
        $request->session()->put('superadmin_parent_portal_user_id', $parent->id);
        $request->session()->put('superadmin_parent_portal_actor_id', $request->user()->id);
    }

    protected function hasParentContact(Member $member): bool
    {
        $contact = $this->parentContact($member);

        return filled($contact['parent_name'] ?? null);
    }

    protected function parentContact(Member $member): array
    {
        $member->loadMissing(['club.church']);

        $detail = $this->memberDetail($member);
        $club = $member->club;
        $church = $club?->church;

        if ($detail instanceof MemberAdventurer) {
            return [
                'member_name' => $detail->applicant_name,
                'parent_name' => $detail->parent_name,
                'parent_email' => $detail->email_address,
                'parent_phone' => $detail->parent_cell,
                'club_id' => $club?->id ?? $detail->club_id,
                'club_name' => $club?->club_name ?? $detail->club_name,
                'church_id' => $club?->church_id,
                'church_name' => $church?->church_name ?? $club?->church_name ?? $detail->church_name,
                'legacy_parent_member_id' => $detail->id,
            ];
        }

        if ($detail instanceof MemberPathfinder) {
            $useFather = filled($detail->father_guardian_name);

            return [
                'member_name' => $detail->applicant_name,
                'parent_name' => $useFather ? $detail->father_guardian_name : $detail->mother_guardian_name,
                'parent_email' => $useFather ? $detail->father_guardian_email : $detail->mother_guardian_email,
                'parent_phone' => $useFather ? $detail->father_guardian_phone : $detail->mother_guardian_phone,
                'club_id' => $club?->id ?? $detail->club_id,
                'club_name' => $club?->club_name ?? $detail->club_name,
                'church_id' => $club?->church_id,
                'church_name' => $church?->church_name ?? $club?->church_name ?? $detail->church_name,
                'legacy_parent_member_id' => $detail->id,
            ];
        }

        return [
            'member_name' => null,
            'parent_name' => null,
            'parent_email' => null,
            'parent_phone' => null,
            'club_id' => $club?->id,
            'club_name' => $club?->club_name,
            'church_id' => $club?->church_id,
            'church_name' => $church?->church_name ?? $club?->church_name,
            'legacy_parent_member_id' => null,
        ];
    }

    protected function memberDetail(Member $member): MemberAdventurer|MemberPathfinder|null
    {
        if ($member->type === 'adventurers') {
            return MemberAdventurer::query()->find($member->id_data);
        }

        if (in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
            return MemberPathfinder::query()
                ->where('member_id', $member->id)
                ->orWhere('id', $member->id_data)
                ->first();
        }

        return null;
    }

    protected function syncLegacyParentMemberLink(User $parent, Member $member, array $contact): void
    {
        if (empty($contact['legacy_parent_member_id']) || empty($contact['club_id']) || empty($contact['church_id'])) {
            return;
        }

        ParentMember::firstOrCreate([
            'user_id' => $parent->id,
            'member_id' => $contact['legacy_parent_member_id'],
            'club_id' => $contact['club_id'],
            'church_id' => $contact['church_id'],
        ]);
    }
}
