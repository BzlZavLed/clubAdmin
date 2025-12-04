<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplan_event_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('staff')->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('club_classes')->nullOnDelete();
            $table->enum('type', ['plan', 'outing'])->default('plan');
            $table->boolean('requires_approval')->default(false);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('requested_date')->nullable();
            $table->string('location_override')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_plans');
    }
};
