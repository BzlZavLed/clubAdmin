<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('members_adventurers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->onDelete('cascade');
            $table->string('club_name');
            $table->string('director_name');
            $table->string('church_name');

            $table->string('applicant_name');
            $table->date('birthdate');
            $table->integer('age');
            $table->string('grade');
            $table->string('mailing_address');
            $table->string('cell_number');
            $table->string('emergency_contact');

            $table->json('investiture_classes')->nullable();

            $table->text('allergies')->nullable();
            $table->text('physical_restrictions')->nullable();
            $table->text('health_history')->nullable();

            $table->string('parent_name');
            $table->string('parent_cell');
            $table->string('home_address');
            $table->string('email_address');
            $table->string('signature');

            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
