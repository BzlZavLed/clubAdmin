<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_master_guides')) {
            return;
        }

        Schema::create('member_master_guides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('club_name')->nullable();
            $table->string('director_name')->nullable();
            $table->string('church_name')->nullable();
            $table->string('applicant_name');
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('emergency_contact_email')->nullable();
            $table->unsignedTinyInteger('program_year')->default(1);
            $table->json('custom_fields_json')->nullable();
            $table->boolean('insurance_paid')->default(false);
            $table->timestamp('insurance_paid_at')->nullable();
            $table->boolean('enrollment_paid')->default(false);
            $table->timestamp('enrollment_paid_at')->nullable();
            $table->string('status')->default('active');
            $table->text('notes_deleted')->nullable();
            $table->timestamps();

            $table->foreign('club_id', 'mg_members_club_fk')->references('id')->on('clubs')->cascadeOnDelete();
            $table->foreign('member_id', 'mg_members_member_fk')->references('id')->on('members')->nullOnDelete();
            $table->unique('member_id', 'mg_members_member_unique');
            $table->index(['club_id', 'status'], 'mg_members_club_status_idx');
            $table->index(['club_id', 'program_year'], 'mg_members_club_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_master_guides');
    }
};
