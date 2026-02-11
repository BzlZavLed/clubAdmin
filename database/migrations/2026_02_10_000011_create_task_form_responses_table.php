<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_task_id')->constrained('event_tasks')->cascadeOnDelete();
            $table->string('schema_key');
            $table->json('data_json');
            $table->timestamps();

            $table->unique('event_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_form_responses');
    }
};
