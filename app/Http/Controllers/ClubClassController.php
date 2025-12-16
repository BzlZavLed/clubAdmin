<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClubClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Staff;
use App\Models\Club;

class ClubClassController extends Controller
{
    // Get all classes
    public function index()
    {
        return ClubClass::with(['club', 'staff.user'])->get();
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
        $class = ClubClass::with(['club', 'staff.user'])->findOrFail($id);
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
            ->where('club_id', $clubId)
            ->orderBy('class_order')
            ->get();

        return response()->json($classes);
    }
}
