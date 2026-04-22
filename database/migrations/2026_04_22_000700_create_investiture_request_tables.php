<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investiture_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_id')->constrained('unions')->cascadeOnDelete();
            $table->foreignId('association_id')->constrained('associations')->cascadeOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('union_carpeta_year_id')->nullable()->constrained('union_carpeta_years')->nullOnDelete();
            $table->unsignedSmallInteger('carpeta_year')->nullable();
            $table->string('club_type');
            $table->string('status')->default('submitted');
            $table->text('director_notes')->nullable();
            $table->text('evaluator_notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('assigned_evaluator_type')->nullable();
            $table->unsignedBigInteger('assigned_evaluator_id')->nullable();
            $table->string('assigned_evaluator_name')->nullable();
            $table->string('assigned_evaluator_email')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['union_id', 'association_id', 'district_id', 'club_id'], 'investiture_requests_hierarchy_idx');
            $table->index(['status', 'carpeta_year'], 'investiture_requests_status_year_idx');
        });

        Schema::create('investiture_request_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investiture_request_id')->constrained('investiture_requests')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('member_name');
            $table->string('class_name')->nullable();
            $table->unsignedInteger('requirements_count')->default(0);
            $table->unsignedInteger('completed_requirements_count')->default(0);
            $table->string('status')->default('pending_review');
            $table->text('evaluator_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['investiture_request_id', 'member_id'], 'investiture_request_members_unique');
            $table->index(['member_id', 'status'], 'investiture_request_members_member_status_idx');
        });

        Schema::create('investiture_requirement_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investiture_request_member_id')->constrained('investiture_request_members')->cascadeOnDelete();
            $table->foreignId('union_carpeta_requirement_id')->constrained('union_carpeta_requirements')->cascadeOnDelete();
            $table->foreignId('parent_carpeta_requirement_evidence_id')->nullable()->constrained('parent_carpeta_requirement_evidences')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['investiture_request_member_id', 'union_carpeta_requirement_id'], 'investiture_requirement_reviews_unique');
            $table->index(['status', 'reviewed_at'], 'investiture_requirement_reviews_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investiture_requirement_reviews');
        Schema::dropIfExists('investiture_request_members');
        Schema::dropIfExists('investiture_requests');
    }
};
