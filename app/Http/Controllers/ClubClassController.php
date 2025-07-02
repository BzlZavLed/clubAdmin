<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClubClass;

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
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'assigned_staff_id' => 'nullable|exists:staff_adventurers,id',
        ]);

        $class = ClubClass::create($validated);

        session()->flash('success', 'Class created successfully.');

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

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'class_order' => 'required|integer',
            'class_name' => 'required|string|max:255',
            'assigned_staff_id' => 'nullable|exists:staff_adventurers,id',
        ]);

        $class->update($validated);

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
        $classes = ClubClass::with('assignedStaff')
            ->where('club_id', $clubId)
            ->orderBy('class_order')
            ->get();

        return response()->json($classes);
    }
}
