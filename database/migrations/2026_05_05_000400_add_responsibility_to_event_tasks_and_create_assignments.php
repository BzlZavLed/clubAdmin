<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_tasks') && !Schema::hasColumn('event_tasks', 'responsibility_level')) {
            Schema::table('event_tasks', function (Blueprint $table) {
                $table->string('responsibility_level')->default('organizer')->after('status');
            });
        }

        if (!Schema::hasTable('event_task_assignments')) {
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
        }

        if (Schema::hasTable('task_form_responses') && !Schema::hasColumn('task_form_responses', 'event_task_assignment_id')) {
            Schema::table('task_form_responses', function (Blueprint $table) {
                $table->foreignId('event_task_assignment_id')
                    ->nullable()
                    ->after('event_task_id')
                    ->constrained('event_task_assignments')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('task_form_responses')) {
            if (!Schema::hasIndex('task_form_responses', 'task_form_responses_task_assignment_idx')) {
                Schema::table('task_form_responses', function (Blueprint $table) {
                    $table->index(['event_task_id', 'event_task_assignment_id'], 'task_form_responses_task_assignment_idx');
                });
            }

            if (Schema::hasIndex('task_form_responses', ['event_task_id'], 'unique')) {
                Schema::table('task_form_responses', function (Blueprint $table) {
                    $table->dropUnique(['event_task_id']);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_form_responses')) {
            if (!Schema::hasIndex('task_form_responses', ['event_task_id'], 'unique')) {
                Schema::table('task_form_responses', function (Blueprint $table) {
                    $table->unique('event_task_id');
                });
            }

            if (Schema::hasIndex('task_form_responses', 'task_form_responses_task_assignment_idx')) {
                Schema::table('task_form_responses', function (Blueprint $table) {
                    $table->dropIndex('task_form_responses_task_assignment_idx');
                });
            }

            if (Schema::hasColumn('task_form_responses', 'event_task_assignment_id')) {
                Schema::table('task_form_responses', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('event_task_assignment_id');
                });
            }
        }

        Schema::dropIfExists('event_task_assignments');

        if (Schema::hasTable('event_tasks') && Schema::hasColumn('event_tasks', 'responsibility_level')) {
            Schema::table('event_tasks', function (Blueprint $table) {
                $table->dropColumn('responsibility_level');
            });
        }
    }
};
