<?php

use App\Models\Association;
use App\Models\District;
use App\Models\Union;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $union = Union::query()->firstOrCreate(
            ['name' => 'Test Union'],
            ['status' => 'active']
        );

        $association = Association::query()->firstOrCreate(
            ['name' => 'Test Association'],
            [
                'union_id' => $union->id,
                'status' => 'active',
            ]
        );

        if ((int) $association->union_id !== (int) $union->id) {
            $association->update(['union_id' => $union->id]);
        }

        $district = District::query()->firstOrCreate(
            ['name' => 'Test District'],
            [
                'association_id' => $association->id,
                'status' => 'active',
            ]
        );

        if ((int) $district->association_id !== (int) $association->id) {
            $district->update(['association_id' => $association->id]);
        }

        $churchId = DB::table('churches')->where('email', 'test-district-church@example.com')->value('id');
        if (!$churchId) {
            $churchId = DB::table('churches')->insertGetId([
                'district_id' => $district->id,
                'church_name' => 'Test District Church',
                'address' => '100 Test District Ave',
                'ethnicity' => null,
                'phone_number' => null,
                'email' => 'test-district-church@example.com',
                'pastor_name' => 'District Pastor',
                'pastor_email' => 'district.pastor.test@example.com',
                'conference' => 'Test Association',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('churches')->where('id', $churchId)->update([
                'district_id' => $district->id,
                'updated_at' => $now,
            ]);
        }

        $users = [
            [
                'name' => 'District Pastor Test',
                'email' => 'district.pastor.test@example.com',
                'profile_type' => 'district_pastor',
                'role_key' => 'district_pastor',
                'scope_type' => 'district',
                'scope_id' => $district->id,
            ],
            [
                'name' => 'District Secretary Test',
                'email' => 'district.secretary.test@example.com',
                'profile_type' => 'district_secretary',
                'role_key' => 'district_secretary',
                'scope_type' => 'district',
                'scope_id' => $district->id,
            ],
            [
                'name' => 'Association Youth Director Test',
                'email' => 'association.youth.test@example.com',
                'profile_type' => 'association_youth_director',
                'role_key' => 'association_youth_director',
                'scope_type' => 'association',
                'scope_id' => $association->id,
            ],
            [
                'name' => 'Union Youth Director Test',
                'email' => 'union.youth.test@example.com',
                'profile_type' => 'union_youth_director',
                'role_key' => 'union_youth_director',
                'scope_type' => 'union',
                'scope_id' => $union->id,
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => $now,
                    'profile_type' => $user['profile_type'],
                    'role_key' => $user['role_key'],
                    'scope_type' => $user['scope_type'],
                    'scope_id' => $user['scope_id'],
                    'sub_role' => null,
                    'church_name' => null,
                    'church_id' => null,
                    'club_id' => null,
                    'status' => 'active',
                    'remember_token' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $emails = [
            'district.pastor.test@example.com',
            'district.secretary.test@example.com',
            'association.youth.test@example.com',
            'union.youth.test@example.com',
        ];

        DB::table('users')->whereIn('email', $emails)->delete();
        DB::table('churches')->where('email', 'test-district-church@example.com')->delete();
        District::query()->where('name', 'Test District')->delete();
        Association::query()->where('name', 'Test Association')->delete();
        Union::query()->where('name', 'Test Union')->delete();
    }
};
