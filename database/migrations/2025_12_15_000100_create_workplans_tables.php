<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workplans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('default_sabbath_location')->nullable();
            $table->string('default_sunday_location')->nullable();
            $table->time('default_sabbath_start_time')->nullable();
            $table->time('default_sabbath_end_time')->nullable();
            $table->time('default_sunday_start_time')->nullable();
            $table->time('default_sunday_end_time')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
        });

        Schema::create('workplan_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_id')->constrained()->onDelete('cascade');
            $table->enum('meeting_type', ['sabbath', 'sunday']);
            $table->unsignedTinyInteger('nth_week'); // 1-5
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('workplan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_id')->constrained()->onDelete('cascade');
            $table->foreignId('generated_from_rule_id')->nullable()->constrained('workplan_rules')->nullOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('meeting_type', ['sabbath', 'sunday', 'special']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->boolean('is_edited')->default(false);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['date', 'meeting_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workplan_events');
        Schema::dropIfExists('workplan_rules');
        Schema::dropIfExists('workplans');
    }
};
