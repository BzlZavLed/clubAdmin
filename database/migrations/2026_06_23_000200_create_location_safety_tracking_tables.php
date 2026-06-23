<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workplan_events', function (Blueprint $table) {
            if (!Schema::hasColumn('workplan_events', 'is_offsite')) {
                $table->boolean('is_offsite')->default(false)->after('location');
            }
            if (!Schema::hasColumn('workplan_events', 'location_tracking_allowed')) {
                $table->boolean('location_tracking_allowed')->default(false)->after('is_offsite');
            }
            if (!Schema::hasColumn('workplan_events', 'location_tracking_requires_parent_consent')) {
                $table->boolean('location_tracking_requires_parent_consent')->default(true)->after('location_tracking_allowed');
            }
            if (!Schema::hasColumn('workplan_events', 'location_tracking_disclosure')) {
                $table->text('location_tracking_disclosure')->nullable()->after('location_tracking_requires_parent_consent');
            }
        });

        Schema::create('location_sharing_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workplan_event_id')->nullable()->constrained('workplan_events')->cascadeOnDelete();
            $table->foreignId('class_plan_id')->nullable()->constrained('class_plans')->cascadeOnDelete();
            $table->string('status', 32)->default('granted');
            $table->string('consent_source', 40)->default('parent_mobile');
            $table->string('terms_version', 40)->nullable();
            $table->text('disclosure_text')->nullable();
            $table->dateTime('granted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['parent_user_id', 'status']);
            $table->index(['workplan_event_id', 'status']);
            $table->unique(['member_id', 'parent_user_id', 'workplan_event_id', 'class_plan_id'], 'location_consent_scope_unique');
        });

        Schema::create('location_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_event_id')->constrained('workplan_events')->cascadeOnDelete();
            $table->foreignId('class_plan_id')->nullable()->constrained('class_plans')->nullOnDelete();
            $table->foreignId('club_class_id')->nullable()->constrained('club_classes')->nullOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('scheduled');
            $table->dateTime('scheduled_starts_at')->nullable();
            $table->dateTime('scheduled_ends_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_reason', 80)->nullable();
            $table->text('disclosure_text')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'status']);
            $table->index(['workplan_event_id', 'status']);
            $table->index(['club_class_id', 'status']);
        });

        Schema::create('location_tracking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_tracking_session_id')->constrained('location_tracking_sessions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('location_sharing_consent_id')->nullable()->constrained('location_sharing_consents')->nullOnDelete();
            $table->string('tracking_status', 32)->default('pending_consent');
            $table->string('device_platform', 40)->nullable();
            $table->string('device_label')->nullable();
            $table->dateTime('last_ping_at')->nullable();
            $table->decimal('last_latitude', 11, 8)->nullable();
            $table->decimal('last_longitude', 12, 8)->nullable();
            $table->decimal('last_accuracy_meters', 8, 2)->nullable();
            $table->unsignedTinyInteger('last_battery_percent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['location_tracking_session_id', 'member_id'], 'location_session_member_unique');
            $table->index(['member_id', 'tracking_status']);
            $table->index(['location_tracking_session_id', 'tracking_status'], 'location_session_participant_status_idx');
        });

        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_tracking_session_id')->constrained('location_tracking_sessions')->cascadeOnDelete();
            $table->foreignId('location_tracking_participant_id')->constrained('location_tracking_participants')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 12, 8);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('altitude_meters', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('is_background')->default(false);
            $table->dateTime('recorded_at');
            $table->dateTime('received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['location_tracking_session_id', 'recorded_at'], 'location_session_recorded_idx');
            $table->index(['member_id', 'recorded_at']);
        });

        Schema::create('location_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_tracking_session_id')->constrained('location_tracking_sessions')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('viewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('viewer_role', 40)->nullable();
            $table->string('action', 64)->default('view');
            $table->json('metadata')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['location_tracking_session_id', 'action'], 'location_access_session_action_idx');
            $table->index(['viewer_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_access_logs');
        Schema::dropIfExists('location_pings');
        Schema::dropIfExists('location_tracking_participants');
        Schema::dropIfExists('location_tracking_sessions');
        Schema::dropIfExists('location_sharing_consents');

        Schema::table('workplan_events', function (Blueprint $table) {
            foreach ([
                'location_tracking_disclosure',
                'location_tracking_requires_parent_consent',
                'location_tracking_allowed',
                'is_offsite',
            ] as $column) {
                if (Schema::hasColumn('workplan_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
