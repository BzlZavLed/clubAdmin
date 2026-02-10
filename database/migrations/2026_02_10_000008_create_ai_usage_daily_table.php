<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('requests_count')->default(0);
            $table->timestamps();

            $table->unique(['club_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
