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
use App\Models\TempMemberPathfinder;
use App\Models\ClassMemberPathfinder;
use Carbon\Carbon;

use DB;
use Auth;
class MemberAdventurerController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'club_id' => 'required|exists:clubs,id',
        ]);

        $club = Club::findOrFail($request->input('club_id'));
        $clubType = strtolower($club->club_type ?? '');
        $parentId = auth()->user()?->profile_type === 'parent' ? auth()->id() : null;

        if ($clubType === 'pathfinders') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'birthdate' => 'required|date',
                'cell_number' => 'required|string',
                'email_address' => 'required|email',
                'parent_name' => 'required|string|max:255',
                'parent_cell' => 'required|string|max:255',
            ]);

            $tempMember = TempMemberPathfinder::create([
                'club_id' => $club->id,
                'nombre' => $validated['applicant_name'],
                'dob' => $validated['birthdate'],
                'phone' => $validated['cell_number'],
                'email' => $validated['email_address'],
                'father_name' => $validated['parent_name'],
                'father_phone' => $validated['parent_cell'],
            ]);

            $member = Member::create([
                'type' => 'temp_pathfinder',
                'id_data' => $tempMember->id,
                'club_id' => $club->id,
                'class_id' => null,
                'parent_id' => $parentId,
                'assigned_staff_id' => null,
                'status' => 'active',
            ]);

            $tempMember->update(['member_id' => $member->id]);
        } else {
            $validated = $request->validate([
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
            $validated['club_id'] = $club->id;
            $validated['club_name'] = $club->club_name ?? $validated['club_name'];
            $validated['director_name'] = $club->director_name ?? $validated['director_name'];
            $validated['church_name'] = $club->church_name ?? $validated['church_name'];

            $member = MemberAdventurer::create($validated);

            Member::firstOrCreate(
                [
                    'type' => 'adventurers',
                    'id_data' => $member->id,
                ],
                [
                    'club_id' => $club->id,
                    'class_id' => null,
                    'parent_id' => $parentId,
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

        $memberRows = \App\Models\Member::where('club_id', $id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->get();

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->all();
        $pathfinderMemberIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id')->all();
        $tempPathfinderIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')->all();

        $pathfinderAssignments = ClassMemberPathfinder::whereIn('member_id', $pathfinderMemberIds)
            ->with(['clubClass:id,club_id,class_order,class_name'])
            ->get()
            ->groupBy('member_id');

        $adventurers = MemberAdventurer::whereIn('id', $adventurerIds)
            ->where('status', 'active')
            ->with(['classAssignments.clubClass'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) use ($memberRows) {
                $memberId = optional($memberRows->firstWhere('id_data', $m->id))->id;
                $m->member_id = $memberId;
                return $m;
            });

        $pathfinderRows = \App\Models\TempMemberPathfinder::whereIn('id', $tempPathfinderIds)->get()
            ->map(function ($row) use ($memberRows, $pathfinderAssignments) {
                $memberId = optional($memberRows->firstWhere('id_data', $row->id))->id;
                $age = null;
                if ($row->dob) {
                    $age = Carbon::parse($row->dob)->age;
                }

                $assignments = [];
                if ($memberId && isset($pathfinderAssignments[$memberId])) {
                    $assignments = $pathfinderAssignments[$memberId]
                        ->map(function ($a) {
                            return [
                                'id' => $a->id,
                                'member_id' => $a->member_id,
                                'club_class_id' => $a->club_class_id,
                                'role' => $a->role,
                                'assigned_at' => optional($a->assigned_at)->toDateString(),
                                'finished_at' => optional($a->finished_at)->toDateString(),
                                'active' => (bool)$a->active,
                                'club_class' => $a->clubClass ? [
                                    'id' => $a->clubClass->id,
                                    'class_name' => $a->clubClass->class_name,
                                    'class_order' => $a->clubClass->class_order,
                                ] : null,
                            ];
                        })
                        ->values()
                        ->all();
                }

                return [
                    'id' => $row->id,
                    'member_id' => $memberId,
                    'member_type' => 'temp_pathfinder',
                    'applicant_name' => $row->nombre,
                    'birthdate' => $row->dob,
                    'age' => $age,
                    'grade' => null,
                    'mailing_address' => null,
                    'cell_number' => $row->phone,
                    'emergency_contact' => null,
                    'investiture_classes' => [],
                    'allergies' => null,
                    'physical_restrictions' => null,
                    'health_history' => null,
                    'parent_name' => $row->father_name,
                    'parent_cell' => $row->father_phone,
                    'home_address' => null,
                    'email_address' => $row->email,
                    'signature' => null,
                    'status' => 'active',
                    'class_assignments' => $assignments,
                ];
            });

        $members = $adventurers->concat($pathfinderRows)->values();

        return response()->json([
            'club' => $club,
            'members' => $members,
        ]);
    }
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
        $data = $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
            // Backward compatibility: frontend previously sent members_adventurer_id (either adventurer id or temp id)
            'members_adventurer_id' => 'nullable|integer',
            'club_class_id' => 'required|exists:club_classes,id',
            'role' => 'nullable|string|max:50',
            'assigned_at' => 'nullable|date',
        ]);

        $member = null;
        if (!empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        } elseif (!empty($data['members_adventurer_id'])) {
            $member = Member::where('type', 'adventurers')->where('id_data', $data['members_adventurer_id'])->first()
                ?? Member::where('type', 'temp_pathfinder')->where('id_data', $data['members_adventurer_id'])->first();
        }

        if (!$member) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        $clubClass = ClubClass::find($data['club_class_id']);
        $newStaffId = $clubClass?->staff()->pluck('staff.id')->first();

        $role = $data['role'] ?? 'student';
        $assignedAt = $data['assigned_at'] ?? now()->toDateString();

        if ($member->type === 'adventurers') {
            $adventurerId = $member->id_data;
            if (!$adventurerId) {
                return response()->json(['message' => 'Adventurer detail missing (id_data)'], 422);
            }

            DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('class_member_adventurer')->insert([
                'members_adventurer_id' => $adventurerId,
                'club_class_id' => $data['club_class_id'],
                'role' => $role,
                'assigned_at' => $assignedAt,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $member->class_id = $data['club_class_id'];
            $member->assigned_staff_id = $newStaffId;
            $member->save();

            return response()->json(['message' => 'Member assigned successfully']);
        }

        if (in_array($member->type, ['temp_pathfinder', 'pathfinders'], true)) {
            ClassMemberPathfinder::where('member_id', $member->id)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            ClassMemberPathfinder::create([
                'member_id' => $member->id,
                'club_class_id' => $data['club_class_id'],
                'role' => $role,
                'assigned_at' => $assignedAt,
                'active' => true,
            ]);

            $member->class_id = $data['club_class_id'];
            $member->assigned_staff_id = $newStaffId;
            $member->save();

            return response()->json(['message' => 'Member assigned successfully']);
        }

        return response()->json(['message' => 'Unsupported member type'], 422);
    }

    public function undoLastAssignment(Request $request)
    {
        $data = $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
            'members_adventurer_id' => 'nullable|integer',
        ]);

        $member = null;
        if (!empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        } elseif (!empty($data['members_adventurer_id'])) {
            $member = Member::where('type', 'adventurers')->where('id_data', $data['members_adventurer_id'])->first()
                ?? Member::where('type', 'temp_pathfinder')->where('id_data', $data['members_adventurer_id'])->first();
        }

        if (!$member) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        if ($member->type === 'adventurers') {
            $adventurerId = $member->id_data;
            if (!$adventurerId) {
                return response()->json(['message' => 'Adventurer detail missing (id_data)'], 422);
            }

            $lastAssignment = DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if (!$lastAssignment) {
                return response()->json(['message' => 'No assignment found to undo'], 404);
            }

            DB::table('class_member_adventurer')
                ->where('id', $lastAssignment->id)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'undone_at' => now(),
                    'updated_at' => now(),
                ]);

            $previous = DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if ($previous) {
                DB::table('class_member_adventurer')
                    ->where('id', $previous->id)
                    ->update([
                        'active' => true,
                        'finished_at' => null,
                        'updated_at' => now(),
                    ]);
                $clubClass = ClubClass::find($previous->club_class_id);
                $member->class_id = $previous->club_class_id;
                $member->assigned_staff_id = $clubClass?->staff()->pluck('staff.id')->first();
            } else {
                $member->class_id = null;
                $member->assigned_staff_id = null;
            }

            $member->save();
            return response()->json(['message' => 'Undo successful']);
        }

        if (in_array($member->type, ['temp_pathfinder', 'pathfinders'], true)) {
            $lastAssignment = ClassMemberPathfinder::where('member_id', $member->id)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if (!$lastAssignment) {
                return response()->json(['message' => 'No assignment found to undo'], 404);
            }

            $lastAssignment->update([
                'active' => false,
                'finished_at' => now(),
                'undone_at' => now(),
            ]);

            $previous = ClassMemberPathfinder::where('member_id', $member->id)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if ($previous) {
                $previous->update([
                    'active' => true,
                    'finished_at' => null,
                ]);
                $clubClass = ClubClass::find($previous->club_class_id);
                $member->class_id = $previous->club_class_id;
                $member->assigned_staff_id = $clubClass?->staff()->pluck('staff.id')->first();
            } else {
                $member->class_id = null;
                $member->assigned_staff_id = null;
            }

            $member->save();
            return response()->json(['message' => 'Undo successful']);
        }

        return response()->json(['message' => 'Unsupported member type'], 422);
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
