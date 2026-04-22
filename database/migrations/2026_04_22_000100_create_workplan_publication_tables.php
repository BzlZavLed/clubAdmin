<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_workplan_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_id')->constrained('unions')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['union_id', 'year']);
        });

        Schema::create('association_workplan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('associations')->cascadeOnDelete();
            $table->foreignId('union_workplan_event_id')->nullable()->constrained('union_workplan_events')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('event_type', ['general', 'program'])->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->json('target_club_types')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['association_id', 'year']);
            $table->index('date');
            $table->unique(['association_id', 'union_workplan_event_id'], 'assoc_union_workplan_event_unique');
        });

        Schema::create('association_workplan_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('associations')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['association_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_workplan_publications');
        Schema::dropIfExists('association_workplan_events');
        Schema::dropIfExists('union_workplan_publications');
    }
};
