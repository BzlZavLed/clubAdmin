<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('union_carpeta_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('union_id')->constrained('unions')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['union_id', 'year'], 'union_carpeta_years_union_year_unique');
            $table->index(['union_id', 'status'], 'union_carpeta_years_union_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('union_carpeta_years');
    }
};
