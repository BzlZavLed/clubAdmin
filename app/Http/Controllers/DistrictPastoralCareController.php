<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\District;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberNote;
use App\Models\MemberPastoralCare;
use App\Models\MemberPathfinder;
use App\Support\SuperadminContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DistrictPastoralCareController extends Controller
{
    public function index(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association:id,name');
        $clubIds = $this->districtClubIds($district);

        $members = Member::query()
            ->with([
                'club:id,club_name,club_type,church_id,church_name,district_id',
                'club.church:id,district_id,church_name',
                'class:id,class_name,class_order',
                'pastoralCare.mentorMember',
                'notes.author:id,name',
            ])
            ->whereIn('club_id', $clubIds)
            ->where('status', 'active')
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where(function ($query) {
                $query->where('is_sda', false)
                    ->orWhereHas('pastoralCare', function ($careQuery) {
                        $careQuery
                            ->where('status', 'new_believer')
                            ->whereDate('new_believer_until', '>=', now()->toDateString());
                    });
            })
            ->orderBy('club_id')
            ->orderBy('id')
            ->get();

        $mentorOptionsByClub = $this->mentorOptionsByClub($clubIds);
        $payload = $members
            ->map(fn (Member $member) => $this->memberPayload($member, $mentorOptionsByClub[(int) $member->club_id] ?? []))
            ->sortBy([
                ['club.name', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        return Inertia::render('District/PastoralCare', [
            'district' => [
                'id' => $district->id,
                'name' => $district->name,
                'pastor_name' => $district->pastor_name,
                'pastor_email' => $district->pastor_email,
            ],
            'association' => [
                'id' => $district->association?->id,
                'name' => $district->association?->name,
            ],
            'summary' => [
                'total' => $payload->count(),
                'non_sda' => $payload->where('status_key', 'non_sda')->count(),
                'new_believers' => $payload->where('status_key', 'new_believer')->count(),
                'bible_studies' => $payload->where('pastoral_care.bible_study_active', true)->count(),
            ],
            'members' => $payload,
        ]);
    }

    public function update(Request $request, Member $member)
    {
        $district = $this->resolveScopedDistrict($request);
        $clubIds = $this->districtClubIds($district);

        if (!in_array((int) $member->club_id, $clubIds, true)) {
            abort(403);
        }

        $validated = $request->validate([
            'bible_study_active' => ['nullable', 'boolean'],
            'bible_study_teacher' => ['nullable', 'string', 'max:255'],
            'bible_study_started_at' => ['nullable', 'date'],
            'baptism_date' => ['nullable', 'date'],
            'mentor_member_id' => ['nullable', 'integer', 'exists:members,id'],
        ]);

        $mentorId = $validated['mentor_member_id'] ?? null;
        if (!empty($validated['baptism_date']) && empty($mentorId)) {
            throw ValidationException::withMessages([
                'mentor_member_id' => 'Selecciona un mentor SDA para completar el seguimiento del bautismo.',
            ]);
        }

        if ($mentorId) {
            $mentor = Member::query()
                ->whereKey($mentorId)
                ->where('club_id', $member->club_id)
                ->where('is_sda', true)
                ->where('status', 'active')
                ->first();

            if (!$mentor) {
                throw ValidationException::withMessages([
                    'mentor_member_id' => 'El mentor debe ser un miembro SDA activo del mismo club.',
                ]);
            }
        }

        $baptismDate = $validated['baptism_date'] ?? null;
        $carePayload = [
            'district_id' => $district->id,
            'bible_study_active' => (bool) ($validated['bible_study_active'] ?? false),
            'bible_study_teacher' => $validated['bible_study_teacher'] ?? null,
            'bible_study_started_at' => $validated['bible_study_started_at'] ?? null,
            'mentor_member_id' => $mentorId,
            'updated_by' => $request->user()?->id,
        ];

        if ($baptismDate) {
            $member->update([
                'is_sda' => true,
                'baptism_date' => $baptismDate,
            ]);

            $carePayload['baptized_at'] = $baptismDate;
            $carePayload['new_believer_until'] = Carbon::parse($baptismDate)->addMonthsNoOverflow(18)->toDateString();
            $carePayload['status'] = 'new_believer';
        } else {
            $carePayload['status'] = $member->is_sda ? 'new_believer' : 'active';
        }

        MemberPastoralCare::query()->updateOrCreate(
            ['member_id' => $member->id],
            $carePayload
        );

        return back()->with('success', 'Seguimiento pastoral actualizado.');
    }

    public function storeNote(Request $request, Member $member)
    {
        $district = $this->resolveScopedDistrict($request);
        $this->assertMemberInDistrict($member, $district);

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string'],
            'color' => ['nullable', 'string', 'in:yellow,blue,green,rose,slate'],
        ]);

        MemberNote::query()->create([
            'member_id' => $member->id,
            'district_id' => $district->id,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
            'context' => 'general',
            'color' => $validated['color'] ?? 'yellow',
        ]);

        return back()->with('success', 'Nota agregada al miembro.');
    }

    public function destroyNote(Request $request, MemberNote $note)
    {
        $district = $this->resolveScopedDistrict($request);
        $note->loadMissing('member');
        $this->assertMemberInDistrict($note->member, $district);

        $note->delete();

        return back()->with('success', 'Nota eliminada.');
    }

    protected function resolveScopedDistrict(Request $request): District
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();
            if (!in_array(($context['role'] ?? null), ['district_pastor', 'district_secretary'], true) || empty($context['district_id'])) {
                abort(403);
            }

            return District::query()->findOrFail((int) $context['district_id']);
        }

        if (!in_array($user->profile_type, ['district_pastor', 'district_secretary'], true) || $user->scope_type !== 'district' || empty($user->scope_id)) {
            abort(403);
        }

        return District::query()->findOrFail((int) $user->scope_id);
    }

    protected function districtClubIds(District $district): array
    {
        return Club::query()
            ->where(function ($query) use ($district) {
                $query->where('district_id', $district->id)
                    ->orWhereHas('church', fn ($churchQuery) => $churchQuery->where('district_id', $district->id));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function assertMemberInDistrict(Member $member, District $district): void
    {
        if (!in_array((int) $member->club_id, $this->districtClubIds($district), true)) {
            abort(403);
        }
    }

    protected function mentorOptionsByClub(array $clubIds): array
    {
        return Member::query()
            ->whereIn('club_id', $clubIds)
            ->where('is_sda', true)
            ->where('status', 'active')
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->get()
            ->map(function (Member $member) {
                $detail = $this->memberDetail($member);

                return [
                    'id' => $member->id,
                    'club_id' => (int) $member->club_id,
                    'name' => $detail['name'] ?? 'Miembro #' . $member->id,
                    'member_type' => $member->type,
                ];
            })
            ->groupBy('club_id')
            ->map(fn ($options) => $options->sortBy('name')->values()->all())
            ->all();
    }

    protected function memberPayload(Member $member, array $mentorOptions): array
    {
        $detail = $this->memberDetail($member);
        $care = $member->pastoralCare;
        $isNewBeliever = $member->is_sda
            && $care?->new_believer_until
            && $care->new_believer_until->greaterThanOrEqualTo(now()->startOfDay());

        return [
            'id' => $member->id,
            'member_type' => $member->type,
            'name' => $detail['name'] ?? 'Miembro #' . $member->id,
            'birthdate' => $detail['birthdate'] ?? null,
            'age' => $detail['age'] ?? null,
            'grade' => $detail['grade'] ?? null,
            'phone' => $detail['phone'] ?? null,
            'email' => $detail['email'] ?? null,
            'address' => $detail['address'] ?? null,
            'parent_name' => $detail['parent_name'] ?? null,
            'parent_phone' => $detail['parent_phone'] ?? null,
            'emergency_contact' => $detail['emergency_contact'] ?? null,
            'health_notes' => $detail['health_notes'] ?? null,
            'class_name' => $member->class?->class_name,
            'is_sda' => (bool) $member->is_sda,
            'baptism_date' => $member->baptism_date?->toDateString(),
            'status_key' => $isNewBeliever ? 'new_believer' : 'non_sda',
            'status_label' => $isNewBeliever ? 'Nuevo creyente en seguimiento' : 'No SDA en cuidado pastoral',
            'club' => [
                'id' => $member->club?->id,
                'name' => $member->club?->club_name,
                'type' => $member->club?->club_type,
                'church_name' => $member->club?->church?->church_name ?? $member->club?->church_name,
            ],
            'pastoral_care' => [
                'bible_study_active' => (bool) ($care?->bible_study_active ?? false),
                'bible_study_teacher' => $care?->bible_study_teacher,
                'bible_study_started_at' => $care?->bible_study_started_at?->toDateString(),
                'baptized_at' => $care?->baptized_at?->toDateString(),
                'mentor_member_id' => $care?->mentor_member_id,
                'mentor_name' => $this->memberDetail($care?->mentorMember)['name'] ?? null,
                'new_believer_until' => $care?->new_believer_until?->toDateString(),
                'status' => $care?->status ?? 'active',
            ],
            'notes' => $member->notes
                ->sortByDesc('created_at')
                ->map(fn (MemberNote $note) => [
                    'id' => $note->id,
                    'subject' => $note->subject,
                    'body' => $note->body,
                    'context' => $note->context,
                    'color' => $note->color,
                    'created_at' => $note->created_at?->toDateTimeString(),
                    'author_name' => $note->author?->name,
                ])
                ->values(),
            'mentor_options' => $mentorOptions,
        ];
    }

    protected function memberDetail(?Member $member): ?array
    {
        if (!$member) {
            return null;
        }

        if ($member->type === 'adventurers' && $member->id_data) {
            $row = MemberAdventurer::query()->find($member->id_data);

            return [
                'name' => $row?->applicant_name,
                'birthdate' => $row?->birthdate?->toDateString(),
                'age' => $row?->age,
                'grade' => $row?->grade,
                'phone' => $row?->cell_number,
                'email' => $row?->email_address,
                'address' => $row?->home_address ?: $row?->mailing_address,
                'parent_name' => $row?->parent_name,
                'parent_phone' => $row?->parent_cell,
                'emergency_contact' => $row?->emergency_contact,
                'health_notes' => collect([$row?->health_history, $row?->allergies, $row?->physical_restrictions])->filter()->implode(' | '),
            ];
        }

        if (in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
            $row = MemberPathfinder::query()->find($member->id_data);

            return [
                'name' => $row?->applicant_name,
                'birthdate' => $row?->birthdate?->toDateString(),
                'age' => $row?->birthdate ? Carbon::parse($row->birthdate)->age : null,
                'grade' => $row?->grade,
                'phone' => $row?->cell_number,
                'email' => $row?->email_address,
                'address' => collect([$row?->mailing_address, $row?->city, $row?->state, $row?->zip])->filter()->implode(', '),
                'parent_name' => $row?->father_guardian_name ?: $row?->mother_guardian_name,
                'parent_phone' => $row?->father_guardian_phone ?: $row?->mother_guardian_phone,
                'emergency_contact' => collect([$row?->emergency_contact_name, $row?->emergency_contact_phone])->filter()->implode(' - '),
                'health_notes' => collect([
                    $row?->health_history,
                    $row?->disabilities,
                    $row?->medication_allergies,
                    $row?->food_allergies,
                    $row?->physical_restrictions,
                ])->filter()->implode(' | '),
            ];
        }

        return null;
    }
}
