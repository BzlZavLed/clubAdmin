<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DistrictController extends Controller
{
    public function index()
    {
        return Inertia::render('SuperAdmin/Districts', [
            'associations' => Association::query()
                ->with('union:id,name')
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name', 'status']),
            'districts' => District::query()
                ->with('association.union:id,name')
                ->withCount('churches')
                ->orderBy('name')
                ->get(['id', 'association_id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'association_id' => ['required', 'exists:associations,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('association_id', $request->input('association_id'))
                        ->where('status', '!=', 'deleted');
                }),
            ],
        ]);

        District::create([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Distrito creado correctamente.');
    }

    public function update(Request $request, District $district)
    {
        $validated = $request->validate([
            'association_id' => ['required', 'exists:associations,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'name')
                    ->ignore($district->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('association_id', $request->input('association_id'))
                            ->where('status', '!=', 'deleted');
                    }),
            ],
        ]);

        $district->update([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Distrito actualizado correctamente.');
    }

    public function deactivate(District $district)
    {
        $district->update(['status' => 'inactive']);

        return back()->with('success', 'Distrito desactivado correctamente.');
    }

    public function destroy(District $district)
    {
        $district->update(['status' => 'deleted']);

        return back()->with('success', 'Distrito eliminado correctamente.');
    }
}
