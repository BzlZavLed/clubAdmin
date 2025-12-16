<?php

namespace App\Http\Controllers;

use App\Models\MemberAdventurer;
use App\Models\Club;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Str;
use App\Models\StaffAdventurer;
use App\Services\DocumentExportService;
use App\Models\Member;
use App\Models\ClubClass;
use App\Models\Staff;
use App\Support\ClubHelper;

use DB;
use Auth;
class MemberAdventurerController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'club_name' => 'required|string|max:255',
            'director_name' => 'required|string|max:255',
            'church_name' => 'required|string|max:255',

            'applicant_name' => 'required|string|max:255',
            'birthdate' => 'required|date',
            'age' => 'required|integer|min:1|max:99',
            'grade' => 'required|string|max:20',
            'mailing_address' => 'required|string',
            'cell_number' => 'required|string',
            'emergency_contact' => 'required|string',

            'investiture_classes' => 'nullable|array',

            'allergies' => 'nullable|string',
            'physical_restrictions' => 'nullable|string',
            'health_history' => 'nullable|string',

            'parent_name' => 'required|string|max:255',
            'parent_cell' => 'required|string|max:255',
            'home_address' => 'required|string',
            'email_address' => 'required|email',
            'signature' => 'required|string|max:255',
        ]);

        $validated['status'] = 'active';

        $member = MemberAdventurer::create($validated);

        // Mirror into members table for adventurer clubs
        $club = Club::find($validated['club_id']);
        $isAdventurerClub = $club && strtolower($club->club_type ?? '') === 'adventurers';
        if ($isAdventurerClub) {
            Member::firstOrCreate(
                [
                    'type' => 'adventurers',
                    'id_data' => $member->id,
                ],
                [
                    'club_id' => $validated['club_id'],
                    'class_id' => null,
                    'parent_id' => auth()->id(),
                    'assigned_staff_id' => null,
                    'status' => 'active',
                ]
            );
        }

        if (auth()->user()?->profile_type === 'parent') {
            return redirect()->route('parent.dashboard')->with('success', 'Member registered successfully.');
        }

        return redirect()->back()->with('success', 'Member registered successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $member = MemberAdventurer::findOrFail($id);
        $member->update([
            'status' => 'deleted',
            'notes_deleted' => $request['notes_deleted'],
        ]);

        return response()->json(['message' => 'Member deleted.']);
    }

    public function updateForParent(Request $request, $id)
    {
        $member = MemberAdventurer::findOrFail($id);
        $parentId = auth()->id();

        $link = Member::where('type', 'adventurers')
            ->where('id_data', $member->id)
            ->where('parent_id', $parentId)
            ->firstOrFail();

        $validated = $request->validate([
            'applicant_name' => 'required|string|max:255',
            'birthdate' => 'required|date',
            'age' => 'required|integer|min:1|max:99',
            'grade' => 'required|string|max:20',
            'mailing_address' => 'required|string',
            'cell_number' => 'required|string',
            'emergency_contact' => 'required|string',
            'investiture_classes' => 'nullable|array',
            'allergies' => 'nullable|string',
            'physical_restrictions' => 'nullable|string',
            'health_history' => 'nullable|string',
            'parent_name' => 'required|string|max:255',
            'parent_cell' => 'required|string|max:255',
            'home_address' => 'required|string',
            'email_address' => 'required|email',
            'signature' => 'required|string|max:255',
        ]);

        $member->update($validated);

        return redirect()->back()->with('success', 'Child updated.');
    }


    public function byClub($id, Request $request)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $id);

        $memberIdMap = \App\Models\Member::where('club_id', $id)
            ->pluck('id', 'id_data'); // [member_adventurer_id => member_id]

        $members = MemberAdventurer::whereIn('id', $memberIdMap->keys())
            ->where('status', 'active')
            ->with(['classAssignments.clubClass'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) use ($memberIdMap) {
                $m->member_id = $memberIdMap[$m->id] ?? null; // expose new table id
                return $m;
            });

        return response()->json([
            'club' => $club,
            'members' => $members,
        ]);
    }


    /*  public function exportZip(Request $request, string $type, DocumentExportService $exportService)
     {
         $validTypes = ['member', 'staff'];
         if (!in_array($type, $validTypes)) {
             return response()->json(['error' => 'Invalid export type.'], 400);
         }

         $modelClass = $type === 'member' ? MemberAdventurer::class : StaffAdventurer::class;
         $inputKey = $type === 'member' ? 'member_ids' : 'staff_adventurer_ids';
         $generator = $type === 'member'
             ? fn($record, $dir) => $exportService->generateMemberDoc($record, $dir)
             : fn($record, $dir) => $exportService->generateStaffDoc($record, $dir);

         $ids = $request->input($inputKey, []);
         if (!is_array($ids) || empty($ids)) {
             return response()->json(['error' => 'No entries selected.'], 400);
         }

         $records = $modelClass::whereIn('id', $ids)->get();
         $tempDir = storage_path('app/temp_export_' . Str::uuid());
         $zipPath = storage_path("app/temp/{$type}_export_" . time() . ".zip");

         if (!file_exists($tempDir)) {
             mkdir($tempDir, 0775, true);
         }

         foreach ($records as $record) {
             $generator($record, $tempDir);
         }

         $zip = new \ZipArchive;
         if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
             foreach (glob("$tempDir/*.docx") as $file) {
                 $zip->addFile($file, basename($file));
             }
             $zip->close();
         }

         foreach (glob("$tempDir/*.docx") as $file) {
             unlink($file);
         }
         rmdir($tempDir);

         return response()->download($zipPath)->deleteFileAfterSend(true);
     } */

    public function exportWord($id, DocumentExportService $exportService)
    {
        $member = MemberAdventurer::findOrFail($id);
        $outputDir = storage_path('app/temp');

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $outputPath = $exportService->generateMemberDoc($member, $outputDir);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    public function assignMember(Request $request)
    {
        $request->validate([
            'members_adventurer_id' => 'required|exists:members_adventurers,id',
            'club_class_id' => 'required|exists:club_classes,id',
        ]);

        // Optional: deactivate previous active assignment
        DB::table('class_member_adventurer')
            ->where('members_adventurer_id', $request->members_adventurer_id)
            ->where('active', true)
            ->update([
                'active' => false,
                'finished_at' => now(),
            ]);

        DB::table('class_member_adventurer')->insert([
            'members_adventurer_id' => $request->members_adventurer_id,
            'club_class_id' => $request->club_class_id,
            'role' => $request->input('role', 'student'),
            'assigned_at' => $request->input('assigned_at', now()->toDateString()),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update new members table with active class and assigned staff mapping
        $member = Member::where('type', 'adventurers')
            ->where('id_data', $request->members_adventurer_id)
            ->first();
        if ($member) {
            $classId = $request->club_class_id;
            $member->class_id = $classId;
            // With many-to-many staff, pick first staff for reference (optional)
            $newStaffId = $clubClass?->staff()->pluck('staff.id')->first();
            $member->assigned_staff_id = $newStaffId;
            $member->save();
        }

        return response()->json(['message' => 'Member assigned successfully']);
    }

    public function undoLastAssignment(Request $request)
    {
        $request->validate([
            'members_adventurer_id' => 'required|exists:members_adventurers,id',
        ]);

        $memberId = $request->members_adventurer_id;

        // Get the last active assignment (not undone)
        $lastAssignment = DB::table('class_member_adventurer')
            ->where('members_adventurer_id', $memberId)
            ->whereNull('undone_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$lastAssignment) {
            return response()->json(['message' => 'No assignment found to undo'], 404);
        }

        // Mark it as undone
        DB::table('class_member_adventurer')
            ->where('id', $lastAssignment->id)
            ->update([
                'active' => false,
                'finished_at' => now(),
                'undone_at' => now(),
                'updated_at' => now(),
            ]);

        // Restore the previous assignment (if any, also not undone)
        $previousAssignment = DB::table('class_member_adventurer')
            ->where('members_adventurer_id', $memberId)
            ->whereNull('undone_at')
            ->orderByDesc('created_at')
            ->first();

        if ($previousAssignment) {
            DB::table('class_member_adventurer')
                ->where('id', $previousAssignment->id)
                ->update([
                    'active' => true,
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Undo successful']);
    }

    /* private function generateMemberDoc(MemberAdventurer $member, string $outputDir): string
    {
        $templatePath = storage_path('app/templates/template_adventurer_new.docx');
        $processor = new TemplateProcessor($templatePath);

        $processor->setValue('current_date', date('m/d/Y'));
        $processor->setValue('club_name', $member->club_name);
        $processor->setValue('director_name', $member->director_name);
        $processor->setValue('church_name', $member->church_name);

        $processor->setValue('applicant_name', $member->applicant_name);
        $processor->setValue('birthdate', $member->birthdate);
        $processor->setValue('age', $member->age);
        $processor->setValue('grade', $member->grade);
        $processor->setValue('mailing_address', $member->mailing_address);
        $processor->setValue('cell_number', $member->cell_number);
        $processor->setValue('emergency_contact', $member->emergency_contact . " (Cell: " . $member->cell_number . ")");

        $processor->setValue('investiture_classes', is_array($member->investiture_classes) ? implode(', ', $member->investiture_classes) : $member->investiture_classes);
        $processor->setValue('allergies', $member->allergies);
        $processor->setValue('physical_restrictions', $member->physical_restrictions);
        $processor->setValue('health_history', $member->health_history);

        $processor->setValue('signature', $member->signature);
        $processor->setValue('parent_signature', $member->parent_name);
        $processor->setValue('parent_name', $member->parent_name);
        $processor->setValue('parent_cell', $member->parent_cell);
        $processor->setValue('home_address', $member->home_address);
        $processor->setValue('email_address', $member->email_address);

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $filename = "adventurer_member_" . Str::slug($member->applicant_name) . ".docx";
        $outputPath = $outputDir . '/' . $filename;
        $processor->saveAs($outputPath);

        return $outputPath;
    }
 */



}
