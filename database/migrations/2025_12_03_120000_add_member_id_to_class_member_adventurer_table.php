<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('members')) {
            return;
        }
        Schema::table('class_member_adventurer', function (Blueprint $table) {
            if (!Schema::hasColumn('class_member_adventurer', 'member_id')) {
                $table->foreignId('member_id')
                    ->nullable()
                    ->after('members_adventurer_id')
                    ->constrained('members')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_member_adventurer', function (Blueprint $table) {
            if (Schema::hasColumn('class_member_adventurer', 'member_id')) {
                try {
                    $table->dropForeign(['member_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('member_id');
            }
        });
    }
};
