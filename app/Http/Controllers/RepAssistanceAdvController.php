<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use App\Models\Club;
use App\Services\AttendanceDuesPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class RepAssistanceAdvController extends Controller
{
    public function __construct(private readonly AttendanceDuesPaymentService $attendanceDuesPaymentService)
    {
    }

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
            'merits.*.member_id' => 'nullable|integer|exists:members,id',
            'merits.*.asistencia' => 'boolean',
            'merits.*.puntualidad' => 'boolean',
            'merits.*.uniforme' => 'boolean',
            'merits.*.conductor' => 'nullable|boolean',
            'merits.*.cuota' => 'boolean',
            'merits.*.cuota_amount' => 'nullable|numeric',
            'merits.*.requirement_checks_json' => 'nullable|array',
            'merits.*.total' => 'required|integer',
        ]);

        try {
            $report = null;
            DB::transaction(function () use ($validated, &$report, $request) {
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

                $clubType = Club::query()->where('id', $validated['club_id'])->value('club_type');
                $createdMerits = collect();

                // Save each merit row (per member)
                foreach ($validated['merits'] as $entry) {
                    $normalized = $this->normalizeMeritEntry($entry, $clubType);
                    $createdMerits->push(RepAssistanceAdvMerit::create([
                        'report_id' => $report->id,
                        'mem_adv_name' => $entry['mem_adv_name'],
                        'mem_adv_id' => $entry['mem_adv_id'],
                        'member_id' => $entry['member_id'] ?? null,
                        'asistencia' => $normalized['asistencia'],
                        'puntualidad' => $normalized['puntualidad'],
                        'uniforme' => $normalized['uniforme'],
                        'conductor' => $entry['conductor'] ?? false,
                        'cuota' => $normalized['cuota'],
                        'cuota_amount' => $normalized['cuota_amount'],
                        'requirement_checks_json' => $normalized['requirement_checks_json'],
                        'total' => $normalized['total'],
                    ]));
                }

                $this->attendanceDuesPaymentService->syncForReport($report, $createdMerits, $request->user());
            });

            return response()->json(['message' => 'Report created successfully.', 'id' => $report->id], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
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
            'merits.*.member_id' => 'nullable|integer|exists:members,id',
            'merits.*.asistencia' => 'boolean',
            'merits.*.puntualidad' => 'boolean',
            'merits.*.uniforme' => 'boolean',
            'merits.*.conductor' => 'nullable|boolean',
            'merits.*.cuota' => 'boolean',
            'merits.*.cuota_amount' => 'nullable|numeric',
            'merits.*.requirement_checks_json' => 'nullable|array',
            'merits.*.total' => 'required|integer',
        ]);

        try {
            DB::transaction(function () use ($id, $validated, $request) {
                $report = RepAssistanceAdv::findOrFail($id);

                $this->attendanceDuesPaymentService->clearReportPaymentsForEditableReport($report);

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

                $clubType = Club::query()->where('id', $validated['club_id'])->value('club_type');
                $createdMerits = collect();

                // Recreate merit rows
                foreach ($validated['merits'] as $entry) {
                    $normalized = $this->normalizeMeritEntry($entry, $clubType);
                    $createdMerits->push(RepAssistanceAdvMerit::create([
                        'report_id' => $report->id,
                        'mem_adv_name' => $entry['mem_adv_name'],
                        'mem_adv_id' => $entry['mem_adv_id'],
                        'member_id' => $entry['member_id'] ?? null,
                        'asistencia' => $normalized['asistencia'],
                        'puntualidad' => $normalized['puntualidad'],
                        'uniforme' => $normalized['uniforme'],
                        'conductor' => $entry['conductor'] ?? false,
                        'cuota' => $normalized['cuota'],
                        'cuota_amount' => $normalized['cuota_amount'],
                        'requirement_checks_json' => $normalized['requirement_checks_json'],
                        'total' => $normalized['total'],
                    ]));
                }

                $this->attendanceDuesPaymentService->syncForReport($report, $createdMerits, $request->user());
            });

            return response()->json(['message' => 'Report updated successfully.'], 200);
        } catch (ValidationException $e) {
            throw $e;
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

        $reportQuery = RepAssistanceAdv::query()->whereDate('date', $date);

        if ($request->filled('club_id')) {
            $reportQuery->where('club_id', (int) $request->query('club_id'));

            if ($request->has('class_id')) {
                $reportQuery->where('class_id', (int) $request->query('class_id'));
            }
        } else {
            $reportQuery->where('staff_id', $staffId);
        }

        $report = $reportQuery->first();

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

    private function normalizeMeritEntry(array $entry, ?string $clubType): array
    {
        $asistencia = (bool) ($entry['asistencia'] ?? false);
        $puntualidad = $asistencia ? (bool) ($entry['puntualidad'] ?? false) : false;
        $uniforme = $asistencia ? (bool) ($entry['uniforme'] ?? false) : false;
        $cuota = $asistencia ? (bool) ($entry['cuota'] ?? false) : false;
        $checks = $entry['requirement_checks_json'] ?? null;

        if (in_array($clubType, ['pathfinders', 'master_guide'], true)) {
            $puntualidad = false;
            $uniforme = false;
            $checks = null;
        }

        if (!$asistencia) {
            $puntualidad = false;
            $uniforme = false;
            $cuota = false;
            if (is_array($checks)) {
                $checks = array_map(static fn () => false, $checks);
            }
        }

        $cuotaAmount = $cuota ? (float) ($entry['cuota_amount'] ?? 0) : 0.0;

        return [
            'asistencia' => $asistencia,
            'puntualidad' => $puntualidad,
            'uniforme' => $uniforme,
            'cuota' => $cuota,
            'cuota_amount' => $cuotaAmount,
            'requirement_checks_json' => $checks,
            'total' => (int) ($asistencia + $puntualidad + $uniforme + $cuota),
        ];
    }

    public function getBy(string $field, int $value)
    {
        try {
            // Validate that only allowed fields can be queried
            if (!in_array($field, ['staff_id', 'club_id'])) {
                return response()->json(['message' => 'Invalid query field'], 400);
            }

            $reports = RepAssistanceAdv::where($field, $value)
                ->withCount('merits')
                ->orderByDesc('date')
                ->get();

            return response()->json(['reports' => $reports], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getByDate(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        try {
            $date = Carbon::parse($request->date)->toDateString();

            $reports = RepAssistanceAdv::whereDate('date', $date)
                ->withCount('merits')
                ->orderByDesc('date')
                ->get();

            return response()->json(['reports' => $reports], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch reports by date',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getByDateRange(Request $request)
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        try {
            $start = Carbon::parse($request->start)->startOfDay();
            $end = Carbon::parse($request->end)->endOfDay();

            $reports = RepAssistanceAdv::whereBetween('date', [$start, $end])
                ->withCount('merits')
                ->orderByDesc('date')
                ->get();

            return response()->json(['reports' => $reports], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch reports by range',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
