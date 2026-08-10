<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adventurer_induction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_attendee');
            $table->string('club_name');
            $table->date('induction_date');
            $table->time('induction_time');
            $table->string('induction_place');
            $table->text('directions')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('emailed_at')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->string('docx_path')->nullable();
            $table->string('docx_file_name')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'induction_date', 'induction_time'], 'adventurer_induction_requests_unique');
            $table->index(['induction_date', 'club_id'], 'adventurer_induction_requests_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adventurer_induction_requests');
    }
};
