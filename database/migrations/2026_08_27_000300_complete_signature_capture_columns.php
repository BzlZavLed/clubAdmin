<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('members_adventurers')) {
            if (! Schema::hasColumn('members_adventurers', 'signature_type')) {
                Schema::table('members_adventurers', function (Blueprint $table) {
                    $table->string('signature_type', 20)->default('typed')->after('signature');
                });
            }

            if (! Schema::hasColumn('members_adventurers', 'signature_path')) {
                Schema::table('members_adventurers', function (Blueprint $table) {
                    $table->string('signature_path')->nullable()->after('signature_type');
                });
            }

            if (! Schema::hasColumn('members_adventurers', 'signed_at')) {
                Schema::table('members_adventurers', function (Blueprint $table) {
                    $table->date('signed_at')->nullable()->after('signature_path');
                });
            }
        }

        if (Schema::hasTable('members_pathfinders')) {
            if (! Schema::hasColumn('members_pathfinders', 'signature_type')) {
                Schema::table('members_pathfinders', function (Blueprint $table) {
                    $table->string('signature_type', 20)->default('typed')->after('parent_guardian_signature');
                });
            }

            if (! Schema::hasColumn('members_pathfinders', 'signature_path')) {
                Schema::table('members_pathfinders', function (Blueprint $table) {
                    $table->string('signature_path')->nullable()->after('signature_type');
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally left unchanged. These columns may have been created by
        // migration 000200; removing them here could delete signature records.
    }
};
