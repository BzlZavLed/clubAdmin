<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->text('notes_deleted')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('members_adventurers', function (Blueprint $table) {
            $table->dropColumn('notes_deleted');
        });
    }
};
