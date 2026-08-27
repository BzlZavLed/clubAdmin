<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_child_link_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('member_type', 40);
            $table->unsignedBigInteger('id_data');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('match_factors');
            $table->unsignedTinyInteger('matched_count');
            $table->json('identity_snapshot')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status', 'expires_at'], 'parent_child_link_requests_director_idx');
            $table->index(['parent_user_id', 'status'], 'parent_child_link_requests_parent_idx');
            $table->index(['member_type', 'id_data'], 'parent_child_link_requests_member_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_child_link_requests');
    }
};
