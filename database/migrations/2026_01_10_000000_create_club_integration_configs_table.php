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
        Schema::create('club_integration_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('invite_code')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('church_id')->nullable();
            $table->string('church_name')->nullable();
            $table->string('church_slug')->nullable();
            $table->json('departments')->nullable();
            $table->json('objectives')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique('club_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('club_integration_configs');
    }
};
