<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClubClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\StaffAdventurer;
use App\Models\Staff;
use App\Models\Club;

class ClubClassController extends Controller
{
    // Get all classes
    public function index()
    {
        return ClubClass::with(['club', 'assignedStaff'])->get();
    }

    // Create a new class
    public function store(Request $request)
    {
        $hasAssignedStaff = Schema::hasColumn('club_classes', 'assigned_staff_id');
        $assignStaffId = $request->input('assigned_staff_id');

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'assigned_staff_id' => $hasAssignedStaff ? 'nullable|exists:staff_adventurers,id' : 'nullable',
            'user_id' => 'nullable', // Ensure the user exists
        ]);

        if (!$hasAssignedStaff) {
            unset($validated['assigned_staff_id']);
        }

        $class = ClubClass::create($validated);
        $this->syncStaffAssignment($class, $assignStaffId);

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

        session()->flash('success', 'Class created successfully.');
        if (!$hasAssignedStaff) {
            session()->flash('warning', 'Staff assignment column is missing in club_classes; class saved without staff assignment.');
        }

        return back();
    }

    // Get a single class by ID
    public function show($id)
    {
        $class = ClubClass::with(['club', 'assignedStaff'])->findOrFail($id);
        return response()->json($class);
    }

    // Update an existing class
    public function update(Request $request, $id)
    {
        $class = ClubClass::findOrFail($id);

        $hasAssignedStaff = Schema::hasColumn('club_classes', 'assigned_staff_id');
        $assignStaffId = $request->input('assigned_staff_id');

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'assigned_staff_id' => $hasAssignedStaff ? 'nullable|exists:staff_adventurers,id' : 'nullable',
            'user_id' => 'nullable', // Ensure the user exists

        ]);

        if (!$hasAssignedStaff) {
            unset($validated['assigned_staff_id']);
        }

        $class->update($validated);
        $this->syncStaffAssignment($class, $assignStaffId);

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

        session()->flash('success', 'Class updated successfully.');
        if (!$hasAssignedStaff) {
            session()->flash('warning', 'Staff assignment column is missing in club_classes; class saved without staff assignment.');
        }

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
        $classes = ClubClass::with('assignedStaff')
            ->where('club_id', $clubId)
            ->orderBy('class_order')
            ->get();

        return response()->json($classes);
    }

    protected function syncStaffAssignment(ClubClass $class, $staffId): void
    {
        if (!$staffId) {
            return;
        }

        $staff = StaffAdventurer::find($staffId);
        if (!$staff) {
            return;
        }

        $clubType = Club::where('id', $class->club_id)->value('club_type') ?? 'adventurers';

        Staff::updateOrCreate(
            [
                'type' => $clubType,
                'id_data' => $staff->id,
                'club_id' => $staff->club_id,
            ],
            [
                'assigned_class' => $class->id,
                'user_id' => $staff->user_id ?? null,
                'status' => 'active',
            ]
        );
    }
}
