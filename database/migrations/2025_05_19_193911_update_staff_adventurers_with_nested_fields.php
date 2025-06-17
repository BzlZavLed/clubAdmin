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

            $table->dropColumn([
                'experience',
                'award_instruction_abilities', // old string version
                'has_unlawful_conduct',
                'unlawful_conduct_date_place',
                'unlawful_conduct_type',
                'unlawful_conduct_reference',
            ]);
            $table->json('experiences')->nullable()->after('has_health_limitation');
            $table->json('award_instruction_abilities')->nullable()->after('experiences');
            $table->string('unlawful_sexual_conduct')->nullable()->after('award_instruction_abilities');
            $table->json('unlawful_sexual_conduct_records')->nullable()->after('unlawful_sexual_conduct');

            $table->renameColumn('health_limitation_details', 'health_limitation_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_adventurers', function (Blueprint $table) {
            // Revert back if needed
            $table->dropColumn([
                'experiences',
                'award_instruction_abilities',
                'unlawful_sexual_conduct',
                'unlawful_sexual_conduct_records',
            ]);

            $table->string('experience')->nullable();
            $table->string('award_instruction_abilities')->nullable(); // old version
            $table->string('has_unlawful_conduct')->nullable();
            $table->string('unlawful_conduct_date_place')->nullable();
            $table->string('unlawful_conduct_type')->nullable();
            $table->string('unlawful_conduct_reference')->nullable();

            $table->renameColumn('health_limitation_description', 'health_limitation_details');
        });
    }
};
