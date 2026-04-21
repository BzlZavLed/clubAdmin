<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_workplan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_id')->constrained('unions')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('event_type', ['general', 'program'])->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->json('target_club_types')->nullable(); // null = all club types
            $table->boolean('is_mandatory')->default(false);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['union_id', 'year']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('union_workplan_events');
    }
};
