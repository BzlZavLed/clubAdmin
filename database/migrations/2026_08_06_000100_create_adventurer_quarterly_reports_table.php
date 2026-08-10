<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adventurer_quarterly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reporting_year');
            $table->string('reporting_period', 20);
            $table->date('due_date');
            $table->timestamp('submitted_at');
            $table->boolean('submitted_on_time')->default(false);
            $table->string('club_name');
            $table->string('director_name');
            $table->string('cell_number', 50)->nullable();
            $table->string('email_address')->nullable();
            $table->unsignedSmallInteger('membership_boys')->default(0);
            $table->unsignedSmallInteger('membership_girls')->default(0);
            $table->unsignedSmallInteger('membership_total')->default(0);
            $table->unsignedSmallInteger('staff_males')->default(0);
            $table->unsignedSmallInteger('staff_females')->default(0);
            $table->unsignedSmallInteger('staff_total')->default(0);
            $table->text('news_item')->nullable();
            $table->unsignedSmallInteger('meetings_held')->default(0);
            $table->boolean('class_a_uniform_worn')->default(false);
            $table->decimal('attendance_percentage', 5, 2)->default(0);
            $table->unsignedSmallInteger('awards_taught')->default(0);
            $table->boolean('curriculum_taught')->default(false);
            $table->string('outreach_activity')->nullable();
            $table->unsignedSmallInteger('staff_meetings_held')->default(0);
            $table->unsignedSmallInteger('meetings_points')->default(0);
            $table->unsignedSmallInteger('uniform_points')->default(0);
            $table->unsignedSmallInteger('attendance_points')->default(0);
            $table->unsignedSmallInteger('awards_points')->default(0);
            $table->unsignedSmallInteger('curriculum_points')->default(0);
            $table->unsignedSmallInteger('outreach_points')->default(0);
            $table->unsignedSmallInteger('staff_meetings_points')->default(0);
            $table->unsignedSmallInteger('promptness_points')->default(0);
            $table->unsignedSmallInteger('news_item_points')->default(0);
            $table->unsignedSmallInteger('total_points')->default(0);
            $table->string('docx_path')->nullable();
            $table->string('docx_file_name')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'reporting_year', 'reporting_period'], 'adventurer_quarterly_reports_unique');
            $table->index(['reporting_year', 'reporting_period', 'club_id'], 'adventurer_quarterly_reports_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adventurer_quarterly_reports');
    }
};
