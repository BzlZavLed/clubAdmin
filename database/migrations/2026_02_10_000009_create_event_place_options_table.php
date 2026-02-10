<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_place_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('place_id')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->unsignedInteger('user_ratings_total')->nullable();
            $table->string('status')->default('tentative'); // tentative/confirmed/rejected
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_place_options');
    }
};
