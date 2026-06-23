<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workplan_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_event_id')->constrained('workplan_events')->cascadeOnDelete();
            $table->foreignId('class_plan_id')->nullable()->constrained('class_plans')->nullOnDelete();
            $table->foreignId('club_class_id')->nullable()->constrained('club_classes')->nullOnDelete();
            $table->foreignId('task_form_schema_id')->nullable()->constrained('task_form_schemas')->nullOnDelete();
            $table->foreignId('union_carpeta_requirement_id')->nullable()->constrained('union_carpeta_requirements')->nullOnDelete();
            $table->foreignId('class_investiture_requirement_id')->nullable()->constrained('class_investiture_requirements')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type', 40)->default('evidence');
            $table->string('assignment_scope', 40)->default('class');
            $table->string('review_mode', 40)->default('staff_approval');
            $table->json('allowed_evidence_types')->nullable();
            $table->json('instructions_json')->nullable();
            $table->unsignedSmallInteger('points')->default(0);
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'status']);
            $table->index(['workplan_event_id', 'status']);
            $table->index(['class_plan_id', 'status']);
            $table->index(['club_class_id', 'status']);
        });

        Schema::create('workplan_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_task_id')->constrained('workplan_tasks')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('assigned');
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workplan_task_id', 'member_id'], 'workplan_task_member_unique');
            $table->index(['member_id', 'status']);
        });

        Schema::create('workplan_task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_task_assignment_id')->constrained('workplan_task_assignments')->cascadeOnDelete();
            $table->foreignId('workplan_task_id')->constrained('workplan_tasks')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_via', 40)->default('member_mobile');
            $table->string('status', 32)->default('submitted');
            $table->longText('text_response')->nullable();
            $table->text('external_url')->nullable();
            $table->json('form_response_json')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workplan_task_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index(['reviewed_by_user_id', 'reviewed_at']);
        });

        Schema::create('workplan_task_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_task_submission_id')->constrained('workplan_task_submissions')->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('evidence_type', 40)->default('file');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workplan_task_submission_id', 'evidence_type'], 'workplan_submission_file_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workplan_task_submission_files');
        Schema::dropIfExists('workplan_task_submissions');
        Schema::dropIfExists('workplan_task_assignments');
        Schema::dropIfExists('workplan_tasks');
    }
};
