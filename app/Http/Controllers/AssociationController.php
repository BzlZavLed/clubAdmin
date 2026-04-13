<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssociationController extends Controller
{
    public function index()
    {
        return Inertia::render('SuperAdmin/Associations', [
            'unions' => Union::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'name', 'status']),
            'associations' => Association::query()
                ->with('union:id,name')
                ->withCount('districts')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('union_id', $request->input('union_id'))
                        ->where('status', '!=', 'deleted');
                }),
            ],
        ]);

        Association::create([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Asociacion creada correctamente.');
    }

    public function update(Request $request, Association $association)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')
                    ->ignore($association->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('union_id', $request->input('union_id'))
                            ->where('status', '!=', 'deleted');
                    }),
            ],
        ]);

        $association->update([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Asociacion actualizada correctamente.');
    }

    public function deactivate(Association $association)
    {
        $association->update(['status' => 'inactive']);

        return back()->with('success', 'Asociacion desactivada correctamente.');
    }

    public function destroy(Association $association)
    {
        $association->update(['status' => 'deleted']);

        return back()->with('success', 'Asociacion eliminada correctamente.');
    }
}
