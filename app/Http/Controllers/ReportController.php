<?php

namespace App\Http\Controllers;

use App\Models\RepAssistanceAdv;
use Illuminate\Support\Carbon;
use Log;
class ReportController extends Controller
{
    public function generateAssistancePDF($id, $date)
    {
        try {
            $parsedDate = Carbon::parse($date)->toDateString();
    
            $report = RepAssistanceAdv::with(['merits', 'staff', 'club'])
                ->where('id', $id)
                ->whereDate('date', $parsedDate)
                ->firstOrFail();
    
            return response()->json($report); // ✅ return raw JSON
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Report not found or failed.',
                'error_details' => $e->getMessage(),
            ], 404);
        }
    }
}
