<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('target_club_types')->nullable()->after('scope_id');
            $table->boolean('is_mandatory')->default(false)->after('requires_approval');
        });

        Schema::create('event_fee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('club_id')->constrained('events')->nullOnDelete();
            $table->foreignId('event_fee_component_id')->nullable()->after('event_id')->constrained('event_fee_components')->nullOnDelete();
        });

        Schema::table('event_target_club', function (Blueprint $table) {
            $table->string('signup_status')->default('targeted')->after('club_id');
            $table->timestamp('signed_up_at')->nullable()->after('signup_status');
            $table->text('signup_notes')->nullable()->after('signed_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_target_club', function (Blueprint $table) {
            $table->dropColumn(['signup_status', 'signed_up_at', 'signup_notes']);
        });

        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropForeign(['event_fee_component_id']);
            $table->dropColumn(['event_id', 'event_fee_component_id']);
        });

        Schema::dropIfExists('event_fee_components');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['target_club_types', 'is_mandatory']);
        });
    }
};
