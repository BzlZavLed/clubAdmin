<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\MemberMasterGuide;
use App\Models\MemberPathfinder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateMobileTestAccounts extends Command
{
    protected $signature = 'mobile:test-accounts {--password=MobileTest123!}';

    protected $description = 'Create local parent, Pathfinder member, and Master Guide member accounts for the mobile app.';

    public function handle(): int
    {
        $password = (string) $this->option('password');

        DB::transaction(function () use ($password) {
            $club = $this->club();
            $class = $this->clubClass($club);

            $parent = User::updateOrCreate(
                ['email' => 'mobile.parent@example.test'],
                [
                    'name' => 'Mobile Test Parent',
                    'password' => Hash::make($password),
                    'profile_type' => 'parent',
                    'role_key' => 'parent',
                    'club_id' => $club->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $pathfinderDetail = MemberPathfinder::updateOrCreate(
                ['email_address' => 'mobile.pathfinder@example.test'],
                [
                    'club_id' => $club->id,
                    'club_name' => $club->club_name,
                    'director_name' => $club->director_name,
                    'church_name' => $club->church_name,
                    'applicant_name' => 'Mobile Test Pathfinder',
                    'birthdate' => now()->subYears(14)->toDateString(),
                    'grade' => '8',
                    'cell_number' => '555-0101',
                    'father_guardian_name' => $parent->name,
                    'father_guardian_email' => $parent->email,
                    'father_guardian_phone' => '555-0100',
                    'consent_acknowledged' => true,
                    'photo_release' => true,
                    'status' => 'active',
                ]
            );

            $pathfinderMember = Member::updateOrCreate(
                [
                    'type' => 'pathfinders',
                    'id_data' => $pathfinderDetail->id,
                ],
                [
                    'club_id' => $club->id,
                    'class_id' => $class->id,
                    'parent_id' => $parent->id,
                    'status' => 'active',
                ]
            );

            if (!$pathfinderDetail->member_id) {
                $pathfinderDetail->member_id = $pathfinderMember->id;
                $pathfinderDetail->save();
            }

            $pathfinderUser = User::updateOrCreate(
                ['email' => 'mobile.pathfinder@example.test'],
                [
                    'name' => 'Mobile Test Pathfinder',
                    'password' => Hash::make($password),
                    'profile_type' => 'member',
                    'role_key' => 'member',
                    'club_id' => $club->id,
                    'mobile_member_id' => $pathfinderMember->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $masterGuideDetail = MemberMasterGuide::updateOrCreate(
                ['email' => 'mobile.masterguide@example.test'],
                [
                    'club_id' => $club->id,
                    'club_name' => $club->club_name,
                    'director_name' => $club->director_name,
                    'church_name' => $club->church_name,
                    'applicant_name' => 'Mobile Test Master Guide',
                    'phone' => '555-0102',
                    'emergency_contact_name' => 'Mobile Emergency Contact',
                    'emergency_contact_phone' => '555-0103',
                    'emergency_contact_email' => 'mobile.parent@example.test',
                    'program_year' => 1,
                    'status' => 'active',
                ]
            );

            $masterGuideMember = Member::updateOrCreate(
                [
                    'type' => 'master_guides',
                    'id_data' => $masterGuideDetail->id,
                ],
                [
                    'club_id' => $club->id,
                    'class_id' => null,
                    'parent_id' => null,
                    'status' => 'active',
                ]
            );

            if ((int) $masterGuideDetail->member_id !== (int) $masterGuideMember->id) {
                $masterGuideDetail->member_id = $masterGuideMember->id;
                $masterGuideDetail->save();
            }

            $masterGuideUser = User::updateOrCreate(
                ['email' => 'mobile.masterguide@example.test'],
                [
                    'name' => 'Mobile Test Master Guide',
                    'password' => Hash::make($password),
                    'profile_type' => 'member',
                    'role_key' => 'member',
                    'club_id' => $club->id,
                    'mobile_member_id' => $masterGuideMember->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $this->line('Mobile test accounts created:');
            $this->line("Parent: {$parent->email}");
            $this->line("Pathfinder member: {$pathfinderUser->email}");
            $this->line("Master Guide member: {$masterGuideUser->email}");
            $this->line("Password: {$password}");
            $this->line("Club: {$club->club_name} (#{$club->id})");
            $this->line("Class: {$class->class_name} (#{$class->id})");
        });

        return self::SUCCESS;
    }

    private function club(): Club
    {
        $club = Club::withoutGlobalScopes()
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($club) {
            return $club;
        }

        return Club::withoutGlobalScopes()->create([
            'club_name' => 'Mobile Test Club',
            'church_name' => 'Mobile Test Church',
            'club_email' => 'club.mobile@example.test',
            'director_name' => 'Mobile Test Director',
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'evaluation_system' => 'honors',
            'status' => 'active',
        ]);
    }

    private function clubClass(Club $club): ClubClass
    {
        return ClubClass::query()->firstOrCreate(
            [
                'club_id' => $club->id,
                'class_name' => 'Friend',
            ],
            [
                'class_order' => 1,
            ]
        );
    }
}
