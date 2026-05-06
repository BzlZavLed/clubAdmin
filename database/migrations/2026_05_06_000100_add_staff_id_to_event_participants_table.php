<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('event_participants', 'staff_id')) {
                $table->foreignId('staff_id')
                    ->nullable()
                    ->after('member_id')
                    ->constrained('staff')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            if (Schema::hasColumn('event_participants', 'staff_id')) {
                $table->dropConstrainedForeignId('staff_id');
            }
        });
    }
};
