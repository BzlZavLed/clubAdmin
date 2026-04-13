<?php

namespace App\Http\Controllers;

use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UnionController extends Controller
{
    public function index()
    {
        return Inertia::render('SuperAdmin/Unions', [
            'unions' => Union::query()
                ->withCount('associations')
                ->orderBy('name')
                ->get(['id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unions', 'name')->where(fn ($query) => $query->where('status', '!=', 'deleted')),
            ],
        ]);

        Union::create([
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Union creada correctamente.');
    }

    public function update(Request $request, Union $union)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unions', 'name')
                    ->ignore($union->id)
                    ->where(fn ($query) => $query->where('status', '!=', 'deleted')),
            ],
        ]);

        $union->update([
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Union actualizada correctamente.');
    }

    public function deactivate(Union $union)
    {
        $union->update(['status' => 'inactive']);

        return back()->with('success', 'Union desactivada correctamente.');
    }

    public function destroy(Union $union)
    {
        $union->update(['status' => 'deleted']);

        return back()->with('success', 'Union eliminada correctamente.');
    }
}
