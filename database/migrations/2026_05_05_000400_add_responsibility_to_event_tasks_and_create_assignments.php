<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->string('responsibility_level')->default('organizer')->after('status');
        });

        Schema::create('event_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_task_id')->constrained('event_tasks')->cascadeOnDelete();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->string('status')->default('todo');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_task_id', 'scope_type', 'scope_id'], 'event_task_scope_unique');
            $table->index(['scope_type', 'scope_id'], 'event_task_scope_lookup');
        });

        Schema::table('task_form_responses', function (Blueprint $table) {
            $table->foreignId('event_task_assignment_id')
                ->nullable()
                ->after('event_task_id')
                ->constrained('event_task_assignments')
                ->cascadeOnDelete();
        });

        Schema::table('task_form_responses', function (Blueprint $table) {
            $table->dropUnique(['event_task_id']);
            $table->index(['event_task_id', 'event_task_assignment_id'], 'task_form_responses_task_assignment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('task_form_responses', function (Blueprint $table) {
            $table->dropIndex('task_form_responses_task_assignment_idx');
            $table->dropConstrainedForeignId('event_task_assignment_id');
            $table->unique('event_task_id');
        });

        Schema::dropIfExists('event_task_assignments');

        Schema::table('event_tasks', function (Blueprint $table) {
            $table->dropColumn('responsibility_level');
        });
    }
};
