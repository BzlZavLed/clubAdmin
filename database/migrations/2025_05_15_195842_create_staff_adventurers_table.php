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
        Schema::create('staff_adventurers', function (Blueprint $table) {
            $table->id();
            $table->date('date_of_record')->nullable();
            $table->string('name');
            $table->date('dob')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('cell_phone')->nullable();
            $table->string('church_and_club_name')->nullable();
            $table->string('email')->nullable();

            $table->boolean('has_health_limitation')->default(false);
            $table->text('health_limitation_details')->nullable();

            $table->json('experience')->nullable();
            $table->json('award_instruction_abilities')->nullable();

            $table->boolean('has_unlawful_conduct')->default(false);
            $table->string('unlawful_conduct_date_place')->nullable();
            $table->string('unlawful_conduct_type')->nullable();
            $table->text('unlawful_conduct_reference')->nullable();

            $table->boolean('sterling_volunteer_completed')->default(false);

            $table->string('reference_pastor')->nullable();
            $table->string('reference_elder')->nullable();
            $table->string('reference_other')->nullable();

            $table->string('applicant_signature');
            $table->date('application_signed_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_adventurers');
    }
};
