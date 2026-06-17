<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pathfinder_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_year', 9);
            $table->string('report_month', 20);
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('area')->nullable();
            $table->string('church_and_club_name')->nullable();
            $table->unsignedInteger('pathfinders_count')->nullable();
            $table->unsignedInteger('tlt_count')->nullable();
            $table->unsignedInteger('staff_count')->nullable();
            $table->unsignedInteger('meetings_count')->nullable();
            $table->unsignedInteger('bible_studies_count')->nullable();
            $table->unsignedInteger('baptisms_count')->nullable();
            $table->unsignedInteger('campouts_count')->nullable();
            $table->unsignedInteger('field_trips_count')->nullable();
            $table->unsignedInteger('honors_completed_count')->nullable();
            $table->text('honors_completed_list')->nullable();
            $table->text('outreach_activities')->nullable();
            $table->text('notable_activities')->nullable();
            $table->boolean('may_share_photos')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_file_name')->nullable();
            $table->string('last_sent_to_email')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'report_year', 'report_month']);
            $table->index(['report_year', 'report_month']);
            $table->index(['delivery_status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pathfinder_monthly_reports');
    }
};
