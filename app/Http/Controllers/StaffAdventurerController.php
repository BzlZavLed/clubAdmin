<?php

namespace App\Http\Controllers;

use App\Models\StaffAdventurer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Services\DocumentExportService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use App\Models\ClassMemberAdventurer; // Import the ClassMemberAdventurer model
use App\Models\MemberAdventurer; // Import the MemberAdventurer model
use App\Models\Staff;
use App\Models\Club;
use Illuminate\Support\Facades\DB as FacadesDB;

use Auth;
use App\Models\ClubClass;
use App\Models\SubRole; // Import the SubRole model

class StaffAdventurerController extends Controller
{
    public function staffView() //TEST VIEW LOADER
    {
        $subRoles = SubRole::all();
        $authUser = Auth::user();

        return Inertia::render('ClubDirector/Staff', [
            'auth_user' => $authUser,
            'sub_roles' => $subRoles,
        ]);
    }
    public function store(Request $request)
    {
        $request->merge([
            'has_health_limitation' => $request->input('has_health_limitation') === 'yes' ? 1 : 0,
            'unlawful_sexual_conduct' => $request->input('unlawful_sexual_conduct') === 'yes' ? 1 : 0,
            'sterling_volunteer_completed' => $request->input('sterling_volunteer_completed') === 'yes' ? 1 : 0,
        ]);
        $validated = $request->validate([
            'date_of_record' => 'required|date',
            'name' => 'required|string|max:255',
            'dob' => 'required|date',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:50',
            'zip' => 'required|string|max:20',
            'cell_phone' => 'nullable|string|max:20',
            'church_name' => 'required|string|max:255',
            'church_id' => 'required',
            'email' => 'nullable|email|max:255',
            'club_name' => 'required|string|max:255',
            'club_id' => 'required|exists:clubs,id',
            // Health limitation section
            'has_health_limitation' => 'required|boolean',
            'health_limitation_description' => 'nullable|string',

            // Experience section (array of 3 objects)
            'experiences' => 'nullable|array',
            'experiences.*.position' => 'nullable|string|max:255',
            'experiences.*.organization' => 'nullable|string|max:255',
            'experiences.*.date' => 'nullable|string|max:100',

            // Award instruction abilities
            'award_instruction_abilities' => 'nullable|array',
            'award_instruction_abilities.*.name' => 'nullable|string|max:255',
            'award_instruction_abilities.*.level' => 'nullable|in:T,A,I',

            // Unlawful conduct
            'unlawful_sexual_conduct' => 'required|boolean',
            'unlawful_sexual_conduct_records' => 'nullable|array',
            'unlawful_sexual_conduct_records.*.date_place' => 'nullable|string|max:255',
            'unlawful_sexual_conduct_records.*.type' => 'nullable|string|max:255',
            'unlawful_sexual_conduct_records.*.reference' => 'nullable|string|max:255',

            // Background check
            'sterling_volunteer_completed' => 'required|boolean',

            // References
            'reference_pastor' => 'nullable|string|max:255',
            'reference_elder' => 'nullable|string|max:255',
            'reference_other' => 'nullable|string|max:255',

            // Signature
            'applicant_signature' => 'required|string|max:255',
            'application_signed_date' => 'required|date',
        ]);

        DB::beginTransaction();

        try {
            $email = $validated['email'];
            $userId = $request->input('user_id');

            // Ensure the email doesn't exist already
            if (!empty($email)) {
                $emailExistsInStaff = StaffAdventurer::where('email', $email)->exists();

                if ($emailExistsInStaff) {
                    throw new \Exception("The email is already registered as a staff profile.");
                }

                if (!$userId) {
                    $userId = User::where('email', $email)->value('id');
                }
            }

            // Create staff
            $assignedClass = $request->input('assigned_class');
            $validated['status'] = 'active';
            unset($validated['assigned_class']);
            $staff = StaffAdventurer::create($validated);

            // Determine club type for mirroring
            $clubType = Club::where('id', $staff->club_id)->value('club_type') ?? 'adventurers';

            // Mirror into new staff table
            $newStaff = Staff::firstOrCreate(
                [
                    'type' => $clubType,
                    'id_data' => $staff->id,
                    'club_id' => $staff->club_id,
                ],
                [
                    'assigned_class' => $assignedClass,
                    'user_id' => $userId,
                    'status' => 'active',
                ]
            );

            $user = null;

            // Create user if requested and email is provided
            if ($request->boolean('create_user_account') && !empty($validated['email'])) {
                $user = User::firstOrCreate(
                    ['email' => $validated['email']],
                    [
                        'name' => $validated['name'],
                        'church_name' => $validated['church_name'],
                        'church_id' => $request->input('church_id'),
                        'club_id' => $validated['club_id'],
                        'profile_type' => 'club_personal',
                        'sub_role' => 'staff',
                        'password' => bcrypt('password'), // Consider sending a reset email later
                    ]
                );
            }

            // Link user to club if staff has an email
            if (!empty($staff->email)) {
                $linkedUser = $user ?? User::where('email', $staff->email)->first();

                if ($linkedUser) {
                    DB::table('club_user')->updateOrInsert(
                        [
                            'user_id' => $linkedUser->id,
                            'club_id' => $staff->club_id,
                        ],
                        [
                            'status' => 'active',
                            'updated_at' => now(),
                            'created_at' => now(), // Optional; won't affect existing record
                        ]
                    );
                    $linkedUser->club_id = $validated['club_id'];
                    $linkedUser->save();
                }
            }


            DB::commit();

            return response()->json([
                'message' => 'Staff member registered.',
                'staff' => $staff->only(['id', 'name', 'email', 'club_id']),
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Staff creation failed.',
                'error' => $e->getMessage(),
            ], 422);
        }

    }

    public function update(Request $request, $id)
    {
        $staff = StaffAdventurer::findOrFail($id);

        // Preserve original email before updates
        $originalEmail = $staff->email;

        // Normalize inputs
        $request->merge([
            'has_health_limitation' => $request->input('has_health_limitation') === 'yes' ? 1 : 0,
            'unlawful_sexual_conduct' => $request->input('unlawful_sexual_conduct') === 'yes' ? 1 : 0,
            'sterling_volunteer_completed' => $request->input('sterling_volunteer_completed') === 'yes' ? 1 : 0,
        ]);

        $validated = $request->validate([
            'date_of_record' => 'required|date',
            'name' => 'required|string|max:255',
            'dob' => 'required|date',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:50',
            'zip' => 'required|string|max:20',
            'cell_phone' => 'nullable|string|max:20',
            'church_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'club_name' => 'required|string|max:255',
            'assigned_class' => [
                'nullable',
                'integer',
                Rule::exists('club_classes', 'id')->where(fn($q) => $q->where('club_id', $staff->club_id)),
            ],


            // Health limitation section
            'has_health_limitation' => 'required|boolean',
            'health_limitation_description' => 'nullable|string',

            // Experience
            'experiences' => 'nullable|array',
            'experiences.*.position' => 'nullable|string|max:255',
            'experiences.*.organization' => 'nullable|string|max:255',
            'experiences.*.date' => 'nullable|string|max:100',

            // Award instruction abilities
            'award_instruction_abilities' => 'nullable|array',
            'award_instruction_abilities.*.name' => 'nullable|string|max:255',
            'award_instruction_abilities.*.level' => 'nullable|in:T,A,I',

            // Unlawful conduct
            'unlawful_sexual_conduct' => 'required|boolean',
            'unlawful_sexual_conduct_records' => 'nullable|array',
            'unlawful_sexual_conduct_records.*.date_place' => 'nullable|string|max:255',
            'unlawful_sexual_conduct_records.*.type' => 'nullable|string|max:255',
            'unlawful_sexual_conduct_records.*.reference' => 'nullable|string|max:255',

            // Background check
            'sterling_volunteer_completed' => 'required|boolean',

            // References
            'reference_pastor' => 'nullable|string|max:255',
            'reference_elder' => 'nullable|string|max:255',
            'reference_other' => 'nullable|string|max:255',

            // Signature
            'applicant_signature' => 'required|string|max:255',
            'application_signed_date' => 'required|date',
        ]);

        try {
            Log::info('Starting update for staff.', ['staff_id' => $staff->id]);

            $assignedClass = $request->input('assigned_class');
            unset($validated['assigned_class']);
            $staff->update($validated);
            Log::info('Staff updated.', ['staff_id' => $staff->id]);

            // Update new staff table assignment if present
            if (!empty($assignedClass)) {
                Staff::where('id_data', $staff->id)->update([
                    'assigned_class' => $assignedClass,
                    'club_id' => $validated['club_id'],
                ]);
                Log::info('Assigned class updated on staff table.', ['class_id' => $assignedClass]);
            }

            $user = User::where('email', $originalEmail)->first();

            if ($user) {
                $user->update([
                    'name' => $validated['name'],
                    'church_name' => $validated['church_name'],
                    'email' => $validated['email']
                ]);
                Log::info('User updated.', ['user_id' => $user->id]);

                // Insert or update pivot manually
                $pivotResult = DB::table('club_user')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'club_id' => $staff->club_id,
                    ],
                    [
                        'status' => 'active',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                Log::info('club_user pivot updated/inserted.', [
                    'user_id' => $user->id,
                    'club_id' => $staff->club_id,
                    'result' => $pivotResult
                ]);
            } else {
                Log::warning('User not found by original email.', ['email' => $originalEmail]);
            }

            return response()->json([
                'message' => 'Staff member updated (no transaction).',
                'staff' => $staff,
                'user' => $user ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Update failed outside transaction.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Update failed.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy($id)
    {
        $staff = StaffAdventurer::findOrFail($id);
        $staff->delete(); // soft delete

        return response()->json(['message' => 'Staff member deleted.']);
    }

    public function byClub($clubId, $churchId = null)
    {
        $user = Auth::user();
        $authorizedClubIds = $user->clubs()->pluck('clubs.id')->toArray();

        if (!in_array($clubId, $authorizedClubIds)) {
            abort(403, 'Unauthorized access to this club.');
        }

        // Get the club type from the user's club
        $club = $user->clubs()->where('clubs.id', $clubId)->first();
        if (!$club) {
            abort(404, 'Club not found.');
        }

        $staffIdMap = \App\Models\Staff::where('club_id', $clubId)
            ->pluck('id', 'id_data'); // [staff_adventurer_id => staff_id]
        $staffClasses = \App\Models\Staff::with('class')->whereIn('id_data', $staffIdMap->keys())->get()->keyBy('id_data');

        $staff = StaffAdventurer::whereIn('id', $staffIdMap->keys())
            ->get()
            ->map(function ($s) use ($staffIdMap, $staffClasses) {
                $s->staff_id = $staffIdMap[$s->id] ?? null; // expose new table id
                $class = $staffClasses[$s->id]->class ?? null;
                $s->assigned_class_id = $staffClasses[$s->id]->assigned_class ?? null;
                $s->assigned_class_name = $class?->class_name;
                return $s;
            });

        $staff = $staff->map(function ($staffMember) use ($club) {
            $linkedUser = User::where('email', $staffMember->email)->first();
            $staffMember->create_user = !$linkedUser;
            $staffMember->church_id = $club->church_id;
            $staffMember->user_id = $linkedUser?->id;
            return $staffMember;
        });

        $clubUserIds = FacadesDB::table('club_user')->where('club_id', $clubId)->pluck('user_id')->toArray();

        $subRoleUsers = User::when($churchId, function ($query) use ($churchId) {
            return $query->where('church_id', $churchId);
        }, function ($query) use ($clubId) {
            return $query->where('club_id', $clubId);
        })
            ->get()
            ->map(function ($u) use ($clubId) {
                $existsByName = StaffAdventurer::whereRaw('LOWER(name) = ?', [strtolower($u->name)])
                    ->where('club_id', $clubId)
                    ->exists();

                $existsByEmail = StaffAdventurer::where('email', $u->email)
                    ->where('club_id', $clubId)
                    ->exists();

                $u->create_staff = !($existsByName || $existsByEmail);

                return $u;
            });


        return response()->json([
            'staff' => $staff,
            'sub_role_users' => $subRoleUsers,
            'club_user_ids' => $clubUserIds,
        ]);
    }

    /**
     * STAFF ENDPOINTS
     */
    public function checkStaffRecord()
    {
        $user = Auth::user();

        $staffRecord = StaffAdventurer::where('email', $user->email)->first();

        return response()->json([
            'hasStaffRecord' => $staffRecord !== null,
            'staffRecord' => $staffRecord,
            'user' => $user,
        ]);
    }

    public function linkToClub(Request $request, StaffAdventurer $staff)
    {
        $user = User::where('email', $staff->email)->first();
        if (!$user) {
            return response()->json(['message' => 'No user found for staff email.'], 404);
        }

        FacadesDB::table('club_user')->updateOrInsert(
            [
                'user_id' => $user->id,
                'club_id' => $staff->club_id,
            ],
            [
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Staff linked to club users.',
            'user_id' => $user->id,
            'club_id' => $staff->club_id,
        ]);
    }

    public function createUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'church_name' => 'required|string|max:255',
            'church_id' => 'required|integer|exists:churches,id',
            'club_id' => 'required|integer|exists:clubs,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make('password123'),
            'profile_type' => 'club_personal',
            'sub_role' => 'staff',
            'church_name' => $validated['church_name'],
            'church_id' => $validated['church_id'],
            'club_id' => $validated['club_id'],
        ]);

        return response()->json(['message' => 'User created', 'user' => $user], 201);
    }
    public function exportWord($id, DocumentExportService $exportService)
    {
        $staff = StaffAdventurer::findOrFail($id);
        $outputDir = storage_path('app/temp');

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $outputPath = $exportService->generateStaffDoc($staff, $outputDir);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }
    public function updateStaffUserAccount(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status_code' => 'required|integer|between:100,999'
        ]);

        $statusMap = [
            423 => 'active',
            301 => 'deleted',
        ];

        if (!array_key_exists($validated['status_code'], $statusMap)) {
            return response()->json([
                'message' => 'Invalid status code.',
                'success' => false
            ], 422);
        }

        $user = User::find($validated['user_id']);

        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
                'success' => false
            ], 404);
        }

        if (auth()->user()->club_id !== $user->club_id) {
            return response()->json([
                'message' => 'Unauthorized.',
                'success' => false
            ], 403);
        }

        $newStatus = $statusMap[$validated['status_code']];
        $user->status = $newStatus;
        $user->save();

        // Update staff record with matching email if exists
        $staff = StaffAdventurer::where('email', $user->email)->first();
        if ($staff && $staff->club_id === $user->club_id) {
            $staff->status = $newStatus;
            $staff->save();
        }

        return response()->json([
            'message' => 'User account marked as ' . $newStatus,
            'user_id' => $user->id,
            'status' => $newStatus,
            'success' => true
        ]);
    }


    public function updateStaffAccount(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|integer|exists:staff_adventurers,id',
            'status_code' => 'required|integer|between:100,999'
        ]);

        $statusMap = [
            423 => 'active',
            301 => 'deleted',
        ];

        if (!array_key_exists($validated['status_code'], $statusMap)) {
            return response()->json([
                'message' => 'Invalid status code.',
                'success' => false
            ], 422);
        }

        $staff = StaffAdventurer::find($validated['staff_id']);

        if (!$staff) {
            return response()->json([
                'message' => 'Staff not found.',
                'success' => false
            ], 404);
        }

        if (auth()->user()->club_id !== $staff->club_id) {
            return response()->json([
                'message' => 'Unauthorized.',
                'success' => false
            ], 403);
        }

        $newStatus = $statusMap[$validated['status_code']];
        $staff->status = $newStatus;
        $staff->save();

        // Update user account with matching email if exists
        $user = User::where('email', $staff->email)->first();
        if ($user && $user->club_id === $staff->club_id) {
            $user->status = $newStatus;
            $user->save();
        }

        return response()->json([
            'message' => 'Staff account marked as ' . $newStatus,
            'staff_id' => $staff->id,
            'status' => $newStatus,
            'success' => true
        ]);
    }
    public function updateAssignedClass(Request $request)
    {
        $request->validate([
            'staff_id' => 'required|exists:staff_adventurers,id',
            'class_id' => 'required|exists:club_classes,id',
        ]);

        $staff = StaffAdventurer::findOrFail($request->staff_id);
        $clubType = Club::where('id', $staff->club_id)->value('club_type') ?? 'adventurers';

        Staff::updateOrCreate(
            [
                'type' => $clubType,
                'id_data' => $staff->id,
                'club_id' => $staff->club_id,
            ],
            [
                'assigned_class' => $request->class_id,
                'user_id' => $staff->user_id ?? null,
                'status' => 'active',
            ]
        );

        return response()->json(['message' => 'Assigned class updated']);
    }

    public function updatePassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:8'
        ]);

        $user = User::findOrFail($id);
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function getAssignedMembersByStaff($staffId)
    {
        // Step 1: Get the class assigned to the staff
        $assignedClass = ClubClass::where('assigned_staff_id', $staffId)->first();

        if (!$assignedClass) {
            return response()->json([
                'message' => 'No class assigned to this staff member.',
                'members' => [],
            ], 404);
        }

        // Step 2: Get student member links for this class
        $studentLinks = ClassMemberAdventurer::where('club_class_id', $assignedClass->id)
            ->where('role', 'student')
            ->where('active', true)
            ->get();

        $memberIds = $studentLinks->pluck('members_adventurer_id');

        // Step 3: Get members based on those IDs
        $members = MemberAdventurer::whereIn('id', $memberIds)->get();

        // Step 4: Update each member with missing staff_id
        foreach ($members as $member) {
            if (is_null($member->staff_id)) {
                $member->staff_id = $staffId;
                $member->save();
            }
        }

        return response()->json([
            'message' => 'Members assigned to this staff fetched successfully.',
            'class' => [
                'id' => $assignedClass->id,
                'name' => $assignedClass->class_name,
            ],
            'members' => $members,
        ]);
    }
    /* private function generateStaffDoc(StaffAdventurer $staff, string $outputDir): string
    {
        $templatePath = storage_path('app/templates/template_staff_new.docx');
        $processor = new TemplateProcessor($templatePath);

        // Basic fields
        $processor->setValue('date_of_record', date('m/d/Y'));
        $processor->setValue('name', $staff->name);
        $processor->setValue('dob', optional($staff->dob)->format('Y-m-d'));
        $processor->setValue('address', $staff->address);
        $processor->setValue('city', $staff->city);
        $processor->setValue('state', $staff->state);
        $processor->setValue('zip', $staff->zip);
        $processor->setValue('cell_phone', $staff->cell_phone);
        $processor->setValue('church_name', $staff->church_name);
        $processor->setValue('club_name', $staff->club_name);
        $processor->setValue('email', $staff->email);

        // Health History
        $processor->setValue('has_health_limitation', $staff->has_health_limitation ? 'Yes' : 'No');
        $processor->setValue('health_limitation_description', $staff->health_limitation_description);

        // Experiences
        $experiences = is_array($staff->experiences)
            ? $staff->experiences
            : json_decode($staff->experiences, true);

        $lines = [];

        foreach ($experiences as $i => $exp) {
            $line = ($i + 1) . '. ';
            $line .= str_pad($exp['position'] ?? '', 50);
            $line .= str_pad($exp['organization'] ?? '', 50);
            $line .= $exp['date'] ?? '';
            $lines[] = $line;
        }

        $formattedBlock = implode("\n", $lines);
        $processor->setValue('experiences_block', $formattedBlock);

        // Awards/Instruction Abilities

        $awards = is_array($staff->award_instruction_abilities)
            ? $staff->award_instruction_abilities
            : json_decode($staff->award_instruction_abilities, true);

        $lines = [];

        foreach ($awards as $i => $aw) {
            $line = ($i + 1) . '. ';
            $line .= str_pad($aw['name'] ?? '', 50);
            $line .= str_pad($aw['level'] ?? '', 50);
            $lines[] = $line;
        }

        $formattedBlock = implode("\n", $lines);
        $processor->setValue('awards_block', $formattedBlock);

        // Unlawful Conduct
        $processor->setValue('unlawful_sexual_conduct', $staff->unlawful_sexual_conduct);
        $conducts = is_array($staff->unlawful_sexual_conduct_records)
            ? $staff->unlawful_sexual_conduct_records
            : json_decode($staff->unlawful_sexual_conduct_records, true);

        $lines = [];

        foreach ($conducts as $index => $entry) {
            $lines[] = ($index + 1) . ". Date & Place: " . ($entry['date_place'] ?? 'N/A');
            $lines[] = "   Type of Conduct: " . ($entry['type'] ?? 'N/A');
            $lines[] = "   Reference name, address and phone: " . ($entry['reference'] ?? 'N/A');
            $lines[] = ""; // Empty line between entries
        }

        $formattedBlock = implode("\n", $lines);

        // Escape XML-sensitive characters to prevent Word corruption
        $cleanedBlock = htmlspecialchars($formattedBlock, ENT_QUOTES | ENT_XML1);

        $processor->setValue('unlawful_conduct_block', $cleanedBlock);


        // Sterling Volunteer
        $processor->setValue('sterling_volunteer_completed', $staff->sterling_volunteer_completed ? 'Yes' : 'No');

        // References
        $processor->setValue('reference_pastor', $staff->reference_pastor);
        $processor->setValue('reference_elder', $staff->reference_elder);
        $processor->setValue('reference_other', $staff->reference_other);

        // Signature
        $processor->setValue('applicant_signature', $staff->applicant_signature);
        $processor->setValue('application_signed_date', optional($staff->application_signed_date)->format('Y-m-d'));

        // Save
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $filename = "adventurer_staff_" . Str::slug($staff->name) . ".docx";
        $outputPath = $outputDir . '/' . $filename;
        $processor->saveAs($outputPath);

        return $outputPath;
    } */
}
