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
        Schema::table('staff_adventurers', function (Blueprint $table) {
            // Rename `experience` to `experiences`
            if (Schema::hasColumn('staff_adventurers', 'experience')) {
                $table->renameColumn('experience', 'experiences');
            }

            // Split `church_and_club_name` into separate columns
            if (Schema::hasColumn('staff_adventurers', 'church_and_club_name')) {
                $table->dropColumn('church_and_club_name');
            }

            $table->string('church_name')->nullable()->after('cell_phone');
            $table->string('club_name')->nullable()->after('church_name');

            if (!Schema::hasColumn('staff_adventurers', 'assigned_class')) {
                Schema::table('staff_adventurers', function (Blueprint $table) {
                    $table->string('assigned_class')->nullable()->after('club_name');
                });
            }
            
            //$table->string('assigned_class')->nullable()->after('club_name');

            // Replace 3 unlawful conduct fields with one JSON column
            if (
                Schema::hasColumn('staff_adventurers', 'unlawful_conduct_date_place') &&
                Schema::hasColumn('staff_adventurers', 'unlawful_conduct_type') &&
                Schema::hasColumn('staff_adventurers', 'unlawful_conduct_reference')
            ) {
                $table->dropColumn([
                    'unlawful_conduct_date_place',
                    'unlawful_conduct_type',
                    'unlawful_conduct_reference'
                ]);
            }

            $table->json('unlawful_conduct_records')->nullable()->after('has_unlawful_conduct');
        });
    }

    public function down(): void
    {
        Schema::table('staff_adventurers', function (Blueprint $table) {
            // Revert back changes if needed
            $table->dropColumn(['church_name', 'club_name', 'assigned_class', 'unlawful_conduct_records']);
            $table->string('unlawful_conduct_date_place')->nullable();
            $table->string('unlawful_conduct_type')->nullable();
            $table->text('unlawful_conduct_reference')->nullable();
            $table->string('church_and_club_name')->nullable();
            $table->dropColumn('experiences');
            $table->json('experience')->nullable();
        });
    }
};
