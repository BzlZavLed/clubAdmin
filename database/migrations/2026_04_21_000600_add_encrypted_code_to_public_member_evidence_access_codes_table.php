<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('public_member_evidence_access_codes', 'code_encrypted')) {
            Schema::table('public_member_evidence_access_codes', function (Blueprint $table) {
                $table->text('code_encrypted')->nullable()->after('code_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('public_member_evidence_access_codes', 'code_encrypted')) {
            Schema::table('public_member_evidence_access_codes', function (Blueprint $table) {
                $table->dropColumn('code_encrypted');
            });
        }
    }
};
