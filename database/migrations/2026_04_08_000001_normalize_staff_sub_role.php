<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('sub_roles')) {
            return;
        }

        DB::table('users')
            ->where('sub_role', 'adviser')
            ->update(['sub_role' => 'staff']);

        $staffSubRole = DB::table('sub_roles')->where('key', 'staff')->first();

        if ($staffSubRole) {
            DB::table('sub_roles')
                ->where('id', $staffSubRole->id)
                ->update([
                    'label' => 'Staff',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('sub_roles')->insert([
                'key' => 'staff',
                'label' => 'Staff',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('sub_roles')->where('key', 'adviser')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('sub_roles')) {
            return;
        }

        DB::table('users')
            ->where('sub_role', 'staff')
            ->update(['sub_role' => 'adviser']);

        $adviserSubRole = DB::table('sub_roles')->where('key', 'adviser')->first();

        if ($adviserSubRole) {
            DB::table('sub_roles')
                ->where('id', $adviserSubRole->id)
                ->update([
                    'label' => 'Staff',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('sub_roles')->insert([
                'key' => 'adviser',
                'label' => 'Staff',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('sub_roles')->where('key', 'staff')->delete();
    }
};
