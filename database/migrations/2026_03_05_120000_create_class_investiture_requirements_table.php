<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_investiture_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_class_id')->constrained('club_classes')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_class_id', 'sort_order'], 'class_investiture_requirements_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_investiture_requirements');
    }
};

