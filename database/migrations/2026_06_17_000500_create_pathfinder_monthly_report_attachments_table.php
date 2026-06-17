<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pathfinder_monthly_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathfinder_monthly_report_id')
                ->constrained('pathfinder_monthly_reports')
                ->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['pathfinder_monthly_report_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pathfinder_monthly_report_attachments');
    }
};
