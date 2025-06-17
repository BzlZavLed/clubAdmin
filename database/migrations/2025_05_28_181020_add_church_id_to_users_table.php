<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add the foreign key column (nullable if optional, remove nullable if required)
            $table->foreignId('church_id')
                ->nullable() // optional: remove if you want to make it required
                ->constrained('churches')
                ->onDelete('set null'); // or 'cascade' if you want users deleted with church
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the foreign key first, then the column
            $table->dropForeign(['church_id']);
            $table->dropColumn('church_id');
        });
    }
};
