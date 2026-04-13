<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_key')->nullable()->after('profile_type');
            $table->string('scope_type')->nullable()->after('role_key');
            $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
        });

        DB::table('users')
            ->select('id', 'profile_type', 'church_id', 'club_id')
            ->orderBy('id')
            ->get()
            ->each(function ($user) {
                $roleKey = $user->profile_type ?: null;
                $scopeType = null;
                $scopeId = null;

                switch ($user->profile_type) {
                    case 'superadmin':
                        $scopeType = 'global';
                        break;
                    case 'club_director':
                    case 'club_personal':
                        $scopeType = $user->club_id ? 'club' : ($user->church_id ? 'church' : null);
                        $scopeId = $user->club_id ?: $user->church_id;
                        break;
                    case 'parent':
                        $scopeType = 'user';
                        $scopeId = $user->id;
                        break;
                    default:
                        $scopeType = $user->club_id ? 'club' : ($user->church_id ? 'church' : null);
                        $scopeId = $user->club_id ?: $user->church_id;
                        break;
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'role_key' => $roleKey,
                        'scope_type' => $scopeType,
                        'scope_id' => $scopeId,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role_key', 'scope_type', 'scope_id']);
        });
    }
};
