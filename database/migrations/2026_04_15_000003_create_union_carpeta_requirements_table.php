<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_carpeta_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_carpeta_year_id')->constrained('union_carpeta_years')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('requirement_type')->default('other');
            $table->string('validation_mode')->default('electronic');
            $table->json('allowed_evidence_types')->nullable();
            $table->text('evidence_instructions')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['union_carpeta_year_id', 'sort_order'], 'union_carpeta_requirements_year_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('union_carpeta_requirements');
    }
};
