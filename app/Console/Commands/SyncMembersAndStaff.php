<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
use App\Models\ClassMemberAdventurer;
use App\Models\Member;
use App\Models\Staff;
use App\Models\ClubClass;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class SyncMembersAndStaff extends Command
{
    protected $signature = 'sync:members-staff';

    protected $description = 'Populate new members/staff tables from legacy Adventurer tables and update class_member_adventurer links';

    public function handle(): int
    {
        $this->info('Syncing members…');
        DB::transaction(function () {
            $activeClassMap = ClassMemberAdventurer::query()
                ->where('active', true)
                ->get(['members_adventurer_id', 'club_class_id'])
                ->keyBy('members_adventurer_id');

            $staffMap = [];

            // Members
            MemberAdventurer::chunk(500, function ($chunk) use ($activeClassMap) {
                foreach ($chunk as $old) {
                    $classId = optional($activeClassMap->get($old->id))->club_class_id;

                    $member = Member::firstOrCreate(
                        [
                            'type' => 'adventurers',
                            'id_data' => $old->id,
                            'club_id' => $old->club_id,
                        ],
                        [
                            'class_id' => $classId,
                            'parent_id' => null,
                            'assigned_staff_id' => null,
                            'status' => 'active',
                        ]
                    );
                }
            });

            // Staff
            StaffAdventurer::chunk(500, function ($chunk) use (&$staffMap) {
                foreach ($chunk as $old) {
                    $userId = null;
                    if ($old->email) {
                        $userId = User::whereRaw('LOWER(email) = ?', [strtolower($old->email)])->value('id');
                    }

                    $staff = Staff::firstOrCreate(
                        [
                            'type' => 'adventurers',
                            'id_data' => $old->id,
                            'club_id' => $old->club_id,
                        ],
                        [
                            'assigned_class' => $old->assigned_class,
                            'user_id' => $userId,
                            'status' => 'active',
                        ]
                    );

                    $changes = [];
                    if (!$staff->user_id && $userId) {
                        $changes[] = "user_id: null -> {$userId}";
                        $staff->user_id = $userId;
                    }
                    if ($staff->assigned_class !== $old->assigned_class) {
                        $changes[] = "assigned_class: {$staff->assigned_class} -> {$old->assigned_class}";
                        $staff->assigned_class = $old->assigned_class;
                    }
                    if ($changes) {
                        $staff->save();
                        $this->info("Staff {$staff->id} ({$old->id}) updated: " . implode(', ', $changes));
                    }
                    $staffMap[$old->id] = $staff->id;
                }
            });

            // Map class -> new staff id based on legacy assigned_staff
            $classAssignments = ClubClass::query()->get(['id', 'assigned_staff_id']);
            $classStaffMap = $classAssignments->mapWithKeys(function ($cls) use ($staffMap) {
                $legacyStaffId = $cls->assigned_staff_id;
                return [$cls->id => ($legacyStaffId ? ($staffMap[$legacyStaffId] ?? null) : null)];
            });

            // Update members with assigned_staff_id based on class mapping
            Member::where('type', 'adventurers')->chunk(500, function ($chunk) use ($activeClassMap, $classStaffMap) {
                foreach ($chunk as $member) {
                    $classId = $member->class_id ?? optional($activeClassMap->get($member->id_data))->club_class_id;
                    $assignedStaffId = $classId ? ($classStaffMap[$classId] ?? null) : null;
                    $changes = [];
                    if ($member->class_id !== $classId) {
                        $changes[] = "class_id: {$member->class_id} -> {$classId}";
                    }
                    if ($member->assigned_staff_id !== $assignedStaffId) {
                        $changes[] = "assigned_staff_id: {$member->assigned_staff_id} -> {$assignedStaffId}";
                    }
                    $member->class_id = $classId;
                    $member->assigned_staff_id = $assignedStaffId;
                    $member->save();
                    if ($changes) {
                        $this->info("Member {$member->id} (" . ($member->id_data) . ") updated: " . implode(', ', $changes));
                    }
                }
            });

            // Link class_member_adventurer to new members
            if (Schema::hasColumn('class_member_adventurer', 'member_id')) {
                ClassMemberAdventurer::chunk(500, function ($chunk) {
                    foreach ($chunk as $pivot) {
                        $member = Member::where('type', 'adventurers')
                            ->where('id_data', $pivot->members_adventurer_id)
                            ->first();
                        if ($member) {
                            $pivot->member_id = $member->id;
                            $pivot->save();
                        }
                    }
                });
            } else {
                $this->warn('member_id column not found on class_member_adventurer; skipping pivot updates.');
            }
        });

        $this->info('Done.');
        return self::SUCCESS;
    }
}
