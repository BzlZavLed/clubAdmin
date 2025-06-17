<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_type')->after('email'); // e.g., club_director, club_personal
            $table->string('sub_role')->nullable()->after('profile_type'); // e.g., treasurer
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_type', 'sub_role']);
        });
    }
};
