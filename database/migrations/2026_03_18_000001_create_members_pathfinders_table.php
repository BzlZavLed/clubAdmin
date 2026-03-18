<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members_pathfinders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('source_temp_member_pathfinder_id')->nullable()->unique();

            $table->string('club_name')->nullable();
            $table->string('director_name')->nullable();
            $table->string('church_name')->nullable();

            $table->string('applicant_name');
            $table->date('birthdate')->nullable();
            $table->string('grade', 50)->nullable();
            $table->string('mailing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 50)->nullable();
            $table->string('zip', 30)->nullable();
            $table->string('school')->nullable();
            $table->string('cell_number', 50)->nullable();
            $table->string('email_address')->nullable();

            $table->string('father_guardian_name')->nullable();
            $table->string('father_guardian_email')->nullable();
            $table->string('father_guardian_phone', 50)->nullable();
            $table->string('mother_guardian_name')->nullable();
            $table->string('mother_guardian_email')->nullable();
            $table->string('mother_guardian_phone', 50)->nullable();

            $table->json('pickup_authorized_people')->nullable();
            $table->boolean('consent_acknowledged')->default(false);
            $table->boolean('photo_release')->default(false);

            $table->text('health_history')->nullable();
            $table->text('disabilities')->nullable();
            $table->text('medication_allergies')->nullable();
            $table->text('food_allergies')->nullable();
            $table->text('dietary_considerations')->nullable();
            $table->text('physical_restrictions')->nullable();
            $table->text('immunization_notes')->nullable();
            $table->text('current_medications')->nullable();

            $table->string('physician_name')->nullable();
            $table->string('physician_phone', 50)->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_number')->nullable();

            $table->string('parent_guardian_signature')->nullable();
            $table->date('signed_at')->nullable();
            $table->json('additional_signatures')->nullable();
            $table->json('application_data')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });

        if (!Schema::hasTable('temp_member_pathfinder')) {
            return;
        }

        $legacyRows = DB::table('temp_member_pathfinder')->orderBy('id')->get();

        foreach ($legacyRows as $row) {
            $newId = DB::table('members_pathfinders')->insertGetId([
                'club_id' => $row->club_id,
                'member_id' => $row->member_id,
                'source_temp_member_pathfinder_id' => $row->id,
                'applicant_name' => $row->nombre,
                'birthdate' => $row->dob,
                'cell_number' => $row->phone,
                'email_address' => $row->email,
                'father_guardian_name' => $row->father_name,
                'father_guardian_phone' => $row->father_phone,
                'status' => 'active',
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);

            DB::table('members')
                ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
                ->where('id_data', $row->id)
                ->update(['id_data' => $newId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('members_pathfinders');
    }
};
