<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_pastoral_care')) {
            return;
        }

        Schema::create('member_pastoral_care', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->boolean('bible_study_active')->default(false);
            $table->string('bible_study_teacher')->nullable();
            $table->date('bible_study_started_at')->nullable();
            $table->date('baptized_at')->nullable();
            $table->foreignId('mentor_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->date('new_believer_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['district_id', 'status'], 'mpc_district_status_idx');
            $table->index(['new_believer_until'], 'mpc_new_believer_until_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_pastoral_care');
    }
};
