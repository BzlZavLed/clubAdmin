<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClubClass;
use App\Models\Staff;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;

class ClubClassController extends Controller
{
    // Get all classes
    public function index()
    {
        $classes = ClubClass::with(['club', 'staff.user', 'investitureRequirements'])->get();
        $this->attachAssignedStaffNames($classes);
        return $classes;
    }

    // Create a new class
    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'user_id' => 'nullable', // optional back-compat: add user to club
        ]);

        $class = ClubClass::create($validated);

        if (!empty($validated['user_id'])) {
            DB::table('club_user')->updateOrInsert(
                [
                    'user_id' => $validated['user_id'],
                    'club_id' => $validated['club_id'],
                ],
                [
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        session()->flash('success', 'Class created successfully.');

        return back();
    }

    // Get a single class by ID
    public function show($id)
    {
        $class = ClubClass::with(['club', 'staff.user', 'investitureRequirements'])->findOrFail($id);
        $this->attachAssignedStaffNames(collect([$class]));
        return response()->json($class);
    }

    // Update an existing class
    public function update(Request $request, $id)
    {
        $class = ClubClass::findOrFail($id);

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'user_id' => 'nullable', // Ensure the user exists

        ]);

        $class->update($validated);

        if (!empty($validated['user_id'])) {
            DB::table('club_user')->updateOrInsert(
                [
                    'user_id' => $validated['user_id'],
                    'club_id' => $validated['club_id'],
                ],
                [
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(), 
                ]
            );
        }

        session()->flash('success', 'Class updated successfully.');

        return back();
    }

    // Delete a class
    public function destroy($id)
    {
        $class = ClubClass::findOrFail($id);
        $class->delete();

        return response()->json(['message' => 'Class deleted']);
    }

    public function getByClubId($clubId)
    {
        $classes = ClubClass::with('staff.user')
            ->with('investitureRequirements')
            ->where('club_id', $clubId)
            ->orderBy('class_order')
            ->get();
        $this->attachAssignedStaffNames($classes);

        return response()->json($classes);
    }

    public function pdf(Request $request)
    {
        return $this->downloadClassesPdf($request, false);
    }

    public function pdfWithRequirements(Request $request)
    {
        return $this->downloadClassesPdf($request, true);
    }

    protected function downloadClassesPdf(Request $request, bool $withRequirements)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $clubId = $request->integer('club_id') ?: null;
        $clubIds = $this->accessibleClubIds($user);

        $query = ClubClass::query()
            ->with(['club:id,club_name', 'investitureRequirements'])
            ->whereIn('club_id', $clubIds);

        if ($clubId) {
            if (!in_array($clubId, $clubIds, true)) {
                abort(403, 'Not allowed to export classes for this club.');
            }
            $query->where('club_id', $clubId);
        }

        $classes = $query
            ->orderBy('club_id')
            ->orderBy('class_order')
            ->orderBy('class_name')
            ->get()
            ->values();
        $this->attachAssignedStaffNames($classes);

        $title = $withRequirements
            ? 'Listado de clases y requisitos de investidura'
            : 'Listado de clases';

        $pdf = Pdf::loadView('pdf.club_classes', [
            'title' => $title,
            'classes' => $classes,
            'withRequirements' => $withRequirements,
            'clubFilter' => $clubId,
            'clubName' => $clubId
                ? optional($classes->first()?->club)->club_name
                : ($classes->pluck('club.club_name')->filter()->unique()->count() === 1
                    ? $classes->pluck('club.club_name')->filter()->first()
                    : 'Varios clubes'),
            'generatedAt' => now()->toDateTimeString(),
        ]);

        $suffix = $withRequirements ? 'with-requirements' : 'classes-only';
        $filename = 'club-classes-' . $suffix . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }

    protected function accessibleClubIds($user): array
    {
        if ($user->profile_type === 'superadmin') {
            return \App\Models\Club::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return ClubHelper::clubIdsForUser($user)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function attachAssignedStaffNames($classes): void
    {
        if ($classes->isEmpty()) {
            return;
        }

        $classIds = $classes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $staffRecords = Staff::query()
            ->whereIn('assigned_class', $classIds)
            ->with('user:id,name')
            ->get(['id', 'id_data', 'assigned_class', 'type', 'user_id']);

        $namesByClass = [];
        foreach ($staffRecords as $staff) {
            $name = $staff->user?->name ?? null;
            if (!$name) {
                $detail = ClubHelper::staffDetail($staff);
                $name = $detail['name'] ?? null;
            }
            if ($name) {
                $classId = (int) $staff->assigned_class;
                if (!isset($namesByClass[$classId])) {
                    $namesByClass[$classId] = [];
                }
                $namesByClass[$classId][] = $name;
            }
        }

        foreach ($classes as $class) {
            $names = $namesByClass[(int) $class->id] ?? [];
            $class->assigned_staff_names = collect($names)->unique()->values()->all();
            $class->assigned_staff_name = !empty($class->assigned_staff_names)
                ? implode(', ', $class->assigned_staff_names)
                : '—';
        }
    }
}
