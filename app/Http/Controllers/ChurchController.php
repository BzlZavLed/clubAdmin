<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Church;
class ChurchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Church::all();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'church_name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'ethnicity' => 'nullable|string',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'required|email|max:255',
            'pastor_name' => 'nullable|string',
            'pastor_email' => 'nullable|email|max:255',
        ]);

        $church = Church::updateOrCreate(
            ['email' => $validated['email']],
            $validated 
        );

        return response()->json([
            'message' => $church->wasRecentlyCreated
                ? 'Church created successfully.'
                : 'Church updated successfully.',
            'church' => $church,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
