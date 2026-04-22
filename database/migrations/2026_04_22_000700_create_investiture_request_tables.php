<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('investiture_requests')) {
            Schema::create('investiture_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('union_id');
                $table->foreign('union_id', 'ir_union_fk')->references('id')->on('unions')->cascadeOnDelete();
                $table->foreignId('association_id');
                $table->foreign('association_id', 'ir_assoc_fk')->references('id')->on('associations')->cascadeOnDelete();
                $table->foreignId('district_id');
                $table->foreign('district_id', 'ir_district_fk')->references('id')->on('districts')->cascadeOnDelete();
                $table->foreignId('club_id');
                $table->foreign('club_id', 'ir_club_fk')->references('id')->on('clubs')->cascadeOnDelete();
                $table->foreignId('union_carpeta_year_id')->nullable();
                $table->foreign('union_carpeta_year_id', 'ir_carpeta_year_fk')->references('id')->on('union_carpeta_years')->nullOnDelete();
                $table->unsignedSmallInteger('carpeta_year')->nullable();
                $table->string('club_type');
                $table->string('status')->default('submitted');
                $table->text('director_notes')->nullable();
                $table->text('evaluator_notes')->nullable();
                $table->foreignId('requested_by')->nullable();
                $table->foreign('requested_by', 'ir_requested_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->string('assigned_evaluator_type')->nullable();
                $table->unsignedBigInteger('assigned_evaluator_id')->nullable();
                $table->string('assigned_evaluator_name')->nullable();
                $table->string('assigned_evaluator_email')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->foreignId('assigned_by')->nullable();
                $table->foreign('assigned_by', 'ir_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable();
                $table->foreign('completed_by', 'ir_completed_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['union_id', 'association_id', 'district_id', 'club_id'], 'investiture_requests_hierarchy_idx');
                $table->index(['status', 'carpeta_year'], 'investiture_requests_status_year_idx');
            });
        }

        if (! Schema::hasTable('investiture_request_members')) {
            Schema::create('investiture_request_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investiture_request_id');
                $table->foreign('investiture_request_id', 'irm_request_fk')->references('id')->on('investiture_requests')->cascadeOnDelete();
                $table->foreignId('member_id');
                $table->foreign('member_id', 'irm_member_fk')->references('id')->on('members')->cascadeOnDelete();
                $table->string('member_name');
                $table->string('class_name')->nullable();
                $table->unsignedInteger('requirements_count')->default(0);
                $table->unsignedInteger('completed_requirements_count')->default(0);
                $table->string('status')->default('pending_review');
                $table->text('evaluator_notes')->nullable();
                $table->foreignId('reviewed_by')->nullable();
                $table->foreign('reviewed_by', 'irm_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['investiture_request_id', 'member_id'], 'investiture_request_members_unique');
                $table->index(['member_id', 'status'], 'investiture_request_members_member_status_idx');
            });
        }

        if (! Schema::hasTable('investiture_requirement_reviews')) {
            Schema::create('investiture_requirement_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investiture_request_member_id');
                $table->foreign('investiture_request_member_id', 'irr_member_fk')->references('id')->on('investiture_request_members')->cascadeOnDelete();
                $table->foreignId('union_carpeta_requirement_id');
                $table->foreign('union_carpeta_requirement_id', 'irr_requirement_fk')->references('id')->on('union_carpeta_requirements')->cascadeOnDelete();
                $table->foreignId('parent_carpeta_requirement_evidence_id')->nullable();
                $table->foreign('parent_carpeta_requirement_evidence_id', 'irr_evidence_fk')->references('id')->on('parent_carpeta_requirement_evidences')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->text('notes')->nullable();
                $table->foreignId('reviewed_by')->nullable();
                $table->foreign('reviewed_by', 'irr_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['investiture_request_member_id', 'union_carpeta_requirement_id'], 'investiture_requirement_reviews_unique');
                $table->index(['status', 'reviewed_at'], 'investiture_requirement_reviews_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('investiture_requirement_reviews')) {
            Schema::drop('investiture_requirement_reviews');
        }

        if (Schema::hasTable('investiture_request_members')) {
            Schema::drop('investiture_request_members');
        }

        if (Schema::hasTable('investiture_requests')) {
            Schema::drop('investiture_requests');
        }
    }
};
