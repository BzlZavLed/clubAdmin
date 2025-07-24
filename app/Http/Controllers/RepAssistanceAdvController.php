<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use Illuminate\Support\Facades\DB;

class RepAssistanceAdvController extends Controller
{
    // 🔹 Store a new report and its merits
    public function store(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|string',
            'year' => 'required|string',
            'date' => 'required|date',
            'class_name' => 'required|string',
            'class_id' => 'required|integer', // Added 'class_id' for reference
            'staff_name' => 'required|string|max:255',
            'staff_id' => 'required|integer', // Added 'staff_id' for reference
            'church' => 'required|string',
            'church_id' => 'required|integer', // Added 'church_id' for reference
            'district' => 'required|string',
            'club_id' => 'required|integer', // Added 'club_id' for reference
            'merits' => 'required|array',
            'merits.*.mem_adv_name' => 'required|string|max:100',
            'merits.*.mem_adv_id' => 'integer',// Added applicant_id for reference
            'merits.*.asistencia' => 'boolean',
            'merits.*.puntualidad' => 'boolean',
            'merits.*.uniforme' => 'boolean',
            'merits.*.conductor' => 'boolean',
            'merits.*.cuota' => 'boolean',
            'merits.*.total' => 'required|integer',
        ]);

        try {
            // Save the main report
            $report = RepAssistanceAdv::create([
                'month' => $validated['month'],
                'year' => $validated['year'],
                'date' => $validated['date'],
                'class_name' => $validated['class_name'],
                'class_id' => $validated['class_id'],// Added 'class_id' for reference
                'staff_name' => $validated['staff_name'],// Changed from 'counselor' to 'staff_name'
                'staff_id' => $validated['staff_id'],// Added 'staff_id' for reference
                'church' => $validated['church'],
                'church_id' => $validated['church_id'],// Added 'church_id' for reference
                'district' => $validated['district'],
                'club_id' => $validated['club_id'], // Added 'club_id' for reference
            ]);

            // Save each merit row (per member)
            foreach ($validated['merits'] as $entry) {
                RepAssistanceAdvMerit::create([
                    'report_id' => $report->id,
                    'mem_adv_name' => $entry['mem_adv_name'],
                    'mem_adv_id' => $entry['mem_adv_id'],
                    'asistencia' => $entry['asistencia'] ?? false,
                    'puntualidad' => $entry['puntualidad'] ?? false,
                    'uniforme' => $entry['uniforme'] ?? false,
                    'conductor' => $entry['conductor'] ?? false,
                    'cuota' => $entry['cuota'] ?? false,
                    'total' => $entry['total'] ?? false
                ]);
            }


            return response()->json(['message' => 'Report created successfully.', 'id' => $report->id], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save report.', 'error' => $e->getMessage()], 500);
        }

    }

    // 🔹 Get all reports with merits
    public function index()
    {
        return RepAssistanceAdv::with('merits')->get();
    }

    // 🔹 Get a single report by ID
    public function show($id)
    {
        $report = RepAssistanceAdv::findOrFail($id);
        $merits = RepAssistanceAdvMerit::where('report_id', $id)->get();

        return response()->json([
            'report' => $report,
            'merits' => $merits,
        ]);
    }

    // 🔹 Update a report and its merits
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'month' => 'required|string',
            'year' => 'required|string',
            'date' => 'required|date',
            'class_name' => 'required|string',
            'class_id' => 'required|integer',
            'staff_name' => 'required|string|max:255',
            'staff_id' => 'required|integer',
            'church' => 'required|string',
            'church_id' => 'required|integer',
            'district' => 'required|string',
            'club_id' => 'required|integer',
            'merits' => 'required|array',
            'merits.*.mem_adv_name' => 'required|string|max:100',
            'merits.*.mem_adv_id' => 'integer',
            'merits.*.asistencia' => 'boolean',
            'merits.*.puntualidad' => 'boolean',
            'merits.*.uniforme' => 'boolean',
            'merits.*.conductor' => 'boolean',
            'merits.*.cuota' => 'boolean',
            'merits.*.total' => 'required|integer',
        ]);

        try {
            $report = RepAssistanceAdv::findOrFail($id);

            // Update main report
            $report->update([
                'month' => $validated['month'],
                'year' => $validated['year'],
                'date' => $validated['date'],
                'class_name' => $validated['class_name'],
                'class_id' => $validated['class_id'],
                'staff_name' => $validated['staff_name'],
                'staff_id' => $validated['staff_id'],
                'church' => $validated['church'],
                'church_id' => $validated['church_id'],
                'district' => $validated['district'],
                'club_id' => $validated['club_id'],
            ]);

            // Delete existing merit entries for this report
            RepAssistanceAdvMerit::where('report_id', $report->id)->delete();

            // Recreate merit rows
            foreach ($validated['merits'] as $entry) {
                RepAssistanceAdvMerit::create([
                    'report_id' => $report->id,
                    'mem_adv_name' => $entry['mem_adv_name'],
                    'mem_adv_id' => $entry['mem_adv_id'],
                    'asistencia' => $entry['asistencia'] ?? false,
                    'puntualidad' => $entry['puntualidad'] ?? false,
                    'uniforme' => $entry['uniforme'] ?? false,
                    'conductor' => $entry['conductor'] ?? false,
                    'cuota' => $entry['cuota'] ?? false,
                    'total' => $entry['total'] ?? 0
                ]);
            }

            return response()->json(['message' => 'Report updated successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update report.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 🔹 Delete a report and its merits
    public function destroy($id)
    {
        $report = RepAssistanceAdv::findOrFail($id);
        $report->merits()->delete();
        $report->delete();

        return response()->json(['message' => 'Report deleted']);
    }

    public function checkTodayReport($staffId, Request $request)
    {
        $date = $request->query('date') ?? now()->toDateString();

        $report = RepAssistanceAdv::where('staff_id', $staffId)
            ->whereDate('date', $date)
            ->first();

        if ($report) {
            $merits = RepAssistanceAdvMerit::where('report_id', $report->id)->get();
            return response()->json([
                'exists' => true,
                'report' => $report,
                'merits' => $merits
            ]);
        }

        return response()->json(['exists' => false]);
    }
}
