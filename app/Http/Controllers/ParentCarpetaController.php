<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubCarpetaClassActivation;
use App\Models\ClassMemberAdventurer;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\ParentCarpetaRequirementEvidence;
use App\Models\UnionCarpetaRequirement;
use App\Models\UnionCarpetaYear;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class ParentCarpetaController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Parent/CarpetaInvestidura', [
            'children' => $this->childrenPayload($request->user()->id),
        ]);
    }

    public function storeEvidence(Request $request)
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'requirement_id' => ['required', 'integer', 'exists:union_carpeta_requirements,id'],
            'evidence_type' => ['required', Rule::in(['photo', 'file', 'text', 'video_link', 'external_link', 'physical_only'])],
            'text_value' => ['nullable', 'string', 'max:5000'],
            'evidence_file' => ['nullable', 'file', 'max:10240'],
            'physical_completed' => ['nullable', 'boolean'],
        ]);

        $member = Member::query()
            ->where('id', (int) $validated['member_id'])
            ->where('parent_id', $request->user()->id)
            ->firstOrFail();

        $requirement = UnionCarpetaRequirement::query()
            ->where('id', (int) $validated['requirement_id'])
            ->where('status', 'active')
            ->firstOrFail();

        $allowedRequirementIds = collect($this->requirementsForMember($member))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_unless($allowedRequirementIds->contains((int) $requirement->id), 403);

        $mode = $requirement->validation_mode ?: 'electronic';
        $evidenceType = $validated['evidence_type'];
        if ($mode === 'physical' && $evidenceType !== 'physical_only') {
            return back()->withErrors(['evidence_type' => 'Este requisito solo permite cumplimiento fisico.']);
        }

        if ($mode !== 'physical') {
            $allowedTypes = collect($requirement->allowed_evidence_types ?: ['text'])
                ->filter(fn ($type) => $type !== 'physical_only')
                ->values();
            if ($allowedTypes->isNotEmpty() && !$allowedTypes->contains($evidenceType)) {
                return back()->withErrors(['evidence_type' => 'Tipo de evidencia no permitido para este requisito.']);
            }
        }

        $filePath = null;
        if (in_array($evidenceType, ['photo', 'file'], true)) {
            if (!$request->hasFile('evidence_file')) {
                return back()->withErrors(['evidence_file' => 'Debe adjuntar un archivo.']);
            }

            $filePath = $request->file('evidence_file')->store("parent-carpeta-evidence/{$member->id}", 'public');
        }

        if (in_array($evidenceType, ['text', 'video_link', 'external_link'], true) && empty($validated['text_value'])) {
            return back()->withErrors(['text_value' => 'Debe completar la evidencia.']);
        }

        $existing = ParentCarpetaRequirementEvidence::query()
            ->where('member_id', $member->id)
            ->where('union_carpeta_requirement_id', $requirement->id)
            ->first();

        if ($existing?->file_path && $filePath && $existing->file_path !== $filePath) {
            Storage::disk('public')->delete($existing->file_path);
        }

        ParentCarpetaRequirementEvidence::query()->updateOrCreate(
            [
                'member_id' => $member->id,
                'union_carpeta_requirement_id' => $requirement->id,
            ],
            [
                'submitted_by_user_id' => $request->user()->id,
                'evidence_type' => $evidenceType,
                'text_value' => $validated['text_value'] ?? null,
                'file_path' => $filePath ?: $existing?->file_path,
                'physical_completed' => $mode === 'physical' ? $request->boolean('physical_completed') : false,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Evidencia guardada.');
    }

    public function pdf(Request $request, Member $member, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        abort_unless((int) $member->parent_id === (int) $request->user()->id, 403);
        abort_unless($member->type === 'adventurers', 404);

        $member->load(['club.church', 'club.district.association.union']);
        $club = $member->club;
        abort_unless(($club?->evaluation_system ?? 'honors') === 'carpetas', 404);

        $detail = MemberAdventurer::query()
            ->where('id', $member->id_data)
            ->first(['id', 'applicant_name', 'birthdate', 'grade', 'parent_name']);

        $requirements = collect($this->requirementsForMember($member));
        $evidences = ParentCarpetaRequirementEvidence::query()
            ->where('member_id', $member->id)
            ->get()
            ->keyBy('union_carpeta_requirement_id');

        abort_if($evidences->isEmpty(), 404, 'No hay evidencias para generar la carpeta.');

        $documentRequirements = $requirements
            ->map(function (array $requirement) use ($evidences) {
                $evidence = $evidences->get($requirement['id']);
                $evidencePayload = null;

                if ($evidence) {
                    $filePath = $evidence->file_path;
                    $absolutePath = $filePath ? storage_path('app/public/' . ltrim($filePath, '/')) : null;
                    $isImage = $filePath ? $this->isImageEvidence($evidence) : false;

                    $evidencePayload = [
                        'id' => $evidence->id,
                        'type' => $evidence->evidence_type,
                        'text_value' => $evidence->text_value,
                        'file_path' => $filePath,
                        'file_url' => $filePath ? url('/storage/' . ltrim($filePath, '/')) : null,
                        'absolute_path' => $absolutePath && file_exists($absolutePath) ? $absolutePath : null,
                        'is_image' => $isImage,
                        'physical_completed' => (bool) $evidence->physical_completed,
                        'status' => $evidence->status,
                        'submitted_at' => optional($evidence->submitted_at)->format('Y-m-d H:i'),
                        'updated_at' => optional($evidence->updated_at)->toISOString(),
                    ];
                }

                return [
                    ...$requirement,
                    'evidence' => $evidencePayload,
                    'completed' => (bool) ($evidence && ($evidence->file_path || $evidence->text_value || $evidence->physical_completed)),
                ];
            })
            ->values()
            ->all();

        $generatedAt = now();
        $checksumSource = [
            'member_id' => $member->id,
            'member_name' => $detail?->applicant_name,
            'club_id' => $club?->id,
            'club_name' => $club?->club_name,
            'church_name' => $club?->church?->church_name ?? $club?->church_name,
            'district_name' => $club?->district?->name,
            'union_name' => $club?->district?->association?->union?->name,
            'pastor_name' => $club?->church?->pastor_name ?? $club?->pastor_name,
            'class_name' => $this->classNameForMember($member),
            'generated_at' => $generatedAt->toISOString(),
            'requirements' => collect($documentRequirements)->map(fn ($requirement) => [
                'requirement_id' => $requirement['id'],
                'title' => $requirement['title'],
                'evidence_id' => $requirement['evidence']['id'] ?? null,
                'evidence_type' => $requirement['evidence']['type'] ?? null,
                'text_value' => $requirement['evidence']['text_value'] ?? null,
                'file_path' => $requirement['evidence']['file_path'] ?? null,
                'physical_completed' => $requirement['evidence']['physical_completed'] ?? false,
                'updated_at' => $requirement['evidence']['updated_at'] ?? null,
            ])->all(),
        ];
        $validation = $documentValidationService->create(
            documentType: 'carpeta_investidura',
            title: 'Carpeta de investidura',
            snapshot: $checksumSource,
            metadata: [
                'Adventurero' => $detail?->applicant_name ?? '—',
                'Club' => $club?->club_name ?? '—',
                'Iglesia' => $club?->church?->church_name ?? $club?->church_name ?? '—',
                'Distrito' => $club?->district?->name ?? '—',
                'Unión' => $club?->district?->association?->union?->name ?? '—',
                'Clase' => $this->classNameForMember($member) ?? '—',
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('pdf.parent_carpeta_portfolio', [
            'member' => $member,
            'detail' => $detail,
            'club' => $club,
            'church' => $club?->church,
            'district' => $club?->district,
            'association' => $club?->district?->association,
            'union' => $club?->district?->association?->union,
            'className' => $this->classNameForMember($member),
            'requirements' => $documentRequirements,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('letter', 'portrait');

        $filename = 'carpeta-investidura-' . $member->id . '-' . $generatedAt->format('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }

    private function childrenPayload(int $parentId): array
    {
        $members = Member::query()
            ->with(['club.district.association.union'])
            ->where('parent_id', $parentId)
            ->where('type', 'adventurers')
            ->where('status', 'active')
            ->get();

        $adventurerRows = MemberAdventurer::query()
            ->whereIn('id', $members->pluck('id_data')->filter())
            ->get(['id', 'applicant_name', 'birthdate', 'grade'])
            ->keyBy('id');

        $evidences = ParentCarpetaRequirementEvidence::query()
            ->whereIn('member_id', $members->pluck('id'))
            ->get()
            ->keyBy(fn ($evidence) => $evidence->member_id . '|' . $evidence->union_carpeta_requirement_id);

        return $members
            ->map(function (Member $member) use ($adventurerRows, $evidences) {
                $club = $member->club;
                if (($club?->evaluation_system ?? 'honors') !== 'carpetas') {
                    return null;
                }

                $requirements = collect($this->requirementsForMember($member))
                    ->map(function (array $requirement) use ($member, $evidences) {
                        $evidence = $evidences->get($member->id . '|' . $requirement['id']);
                        $requirement['evidence'] = $evidence ? [
                            'id' => $evidence->id,
                            'evidence_type' => $evidence->evidence_type,
                            'text_value' => $evidence->text_value,
                            'file_path' => $evidence->file_path,
                            'file_url' => $evidence->file_path ? url('/storage/' . ltrim($evidence->file_path, '/')) : null,
                            'is_image' => $evidence->file_path ? $this->isImageEvidence($evidence) : false,
                            'physical_completed' => (bool) $evidence->physical_completed,
                            'status' => $evidence->status,
                            'submitted_at' => optional($evidence->submitted_at)->toDateTimeString(),
                        ] : null;
                        $requirement['completed'] = $evidence && ($evidence->file_path || $evidence->text_value || $evidence->physical_completed);

                        return $requirement;
                    })
                    ->values()
                    ->all();

                $detail = $adventurerRows->get($member->id_data);

                return [
                    'member_id' => $member->id,
                    'id_data' => $member->id_data,
                    'name' => $detail?->applicant_name ?? '—',
                    'birthdate' => optional($detail?->birthdate)->toDateString(),
                    'grade' => $detail?->grade,
                    'club_name' => $club?->club_name,
                    'class_name' => $this->classNameForMember($member),
                    'requirements' => $requirements,
                    'completed_count' => collect($requirements)->where('completed', true)->count(),
                    'requirements_count' => count($requirements),
                    'has_evidence' => collect($requirements)->contains(fn ($requirement) => !empty($requirement['evidence'])),
                    'all_completed' => count($requirements) > 0 && collect($requirements)->every(fn ($r) => (bool) $r['completed']),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function requirementsForMember(Member $member): array
    {
        $club = Club::query()
            ->with(['district.association.union'])
            ->find($member->club_id);
        $className = $this->classNameForMember($member);
        $unionId = $club?->district?->association?->union?->id;

        if (!$club || !$className || !$unionId) {
            return [];
        }

        $year = UnionCarpetaYear::query()
            ->where('union_id', $unionId)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        if (!$year) {
            return [];
        }

        return UnionCarpetaRequirement::query()
            ->where('union_carpeta_year_id', $year->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn ($requirement) =>
                $this->normalizeClubType($requirement->club_type) === $this->normalizeClubType($club->club_type)
                && $this->normalizeValue($requirement->class_name) === $this->normalizeValue($className)
            )
            ->map(fn ($requirement) => [
                'id' => (int) $requirement->id,
                'title' => $requirement->title,
                'description' => $requirement->description,
                'requirement_type' => $requirement->requirement_type,
                'validation_mode' => $requirement->validation_mode,
                'allowed_evidence_types' => $requirement->allowed_evidence_types ?: [],
                'evidence_instructions' => $requirement->evidence_instructions,
                'sort_order' => $requirement->sort_order,
            ])
            ->values()
            ->all();
    }

    private function classNameForMember(Member $member): ?string
    {
        $clubClass = $this->currentClubClassForMember($member);

        if ($clubClass?->unionClassCatalog) {
            return $clubClass->unionClassCatalog->name;
        }

        return $clubClass?->class_name;
    }

    private function currentClubClassForMember(Member $member): ?ClubClass
    {
        if ($member->id_data) {
            $assignment = ClassMemberAdventurer::query()
                ->with('clubClass.unionClassCatalog')
                ->where('members_adventurer_id', $member->id_data)
                ->where('active', true)
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->first();

            if ($assignment?->clubClass && (int) $assignment->clubClass->club_id === (int) $member->club_id) {
                return $assignment->clubClass;
            }
        }

        if (!$member->class_id) {
            return null;
        }

        $clubClass = ClubClass::query()
            ->with('unionClassCatalog')
            ->where('club_id', $member->club_id)
            ->where('id', $member->class_id)
            ->first();

        if ($clubClass) {
            return $clubClass;
        }

        // Legacy fallback: older carpeta rows sometimes stored activation ids in members.class_id.
        $activation = ClubCarpetaClassActivation::query()
            ->with('unionClassCatalog')
            ->where('club_id', $member->club_id)
            ->where('id', $member->class_id)
            ->first();

        if (!$activation) {
            return null;
        }

        return ClubClass::firstOrCreate(
            [
                'club_id' => $member->club_id,
                'union_class_catalog_id' => $activation->union_class_catalog_id,
            ],
            [
                'class_order' => $activation->unionClassCatalog?->sort_order,
                'class_name' => $activation->unionClassCatalog?->name,
            ]
        );
    }

    private function isImageEvidence(ParentCarpetaRequirementEvidence $evidence): bool
    {
        if ($evidence->evidence_type === 'photo') {
            return true;
        }

        $extension = mb_strtolower(pathinfo((string) $evidence->file_path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    private function normalizeValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function normalizeClubType(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeValue($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guia mayores', 'guia mayor avanzado' => 'master_guide',
            default => $normalized,
        };
    }
}
