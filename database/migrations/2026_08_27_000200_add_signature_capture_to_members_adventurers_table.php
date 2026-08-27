<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->string('signature_type', 20)->default('typed')->after('signature');
            $table->string('signature_path')->nullable()->after('signature_type');
            $table->date('signed_at')->nullable()->after('signature_path');
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->string('signature_type', 20)->default('typed')->after('parent_guardian_signature');
            $table->string('signature_path')->nullable()->after('signature_type');
        });
    }

    public function down(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->dropColumn(['signature_type', 'signature_path', 'signed_at']);
        });

        Schema::table('members_pathfinders', function (Blueprint $table) {
            $table->dropColumn(['signature_type', 'signature_path']);
        });
    }
};
