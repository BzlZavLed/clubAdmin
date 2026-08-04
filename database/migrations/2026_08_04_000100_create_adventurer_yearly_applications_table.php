<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adventurer_yearly_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('application_year', 20);
            $table->date('application_date');
            $table->string('club_name');
            $table->string('sponsoring_church');
            $table->string('pastor')->nullable();
            $table->string('elected_club_director')->nullable();
            $table->string('email_address')->nullable();
            $table->string('cell_number', 50)->nullable();
            $table->string('home_address')->nullable();
            $table->string('church_pastor_signature')->nullable();
            $table->string('head_elder_signature')->nullable();
            $table->string('church_clerk_signature')->nullable();
            $table->string('club_director_signature')->nullable();
            $table->date('signature_date')->nullable();
            $table->json('other_board_members')->nullable();
            $table->string('docx_path')->nullable();
            $table->string('docx_file_name')->nullable();
            $table->string('last_sent_to_email')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'application_year']);
            $table->index(['application_year', 'club_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adventurer_yearly_applications');
    }
};
