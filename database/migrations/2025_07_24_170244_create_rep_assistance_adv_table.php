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
        Schema::create('rep_assistance_adv', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_name');
            $table->string('month');
            $table->string('year');
            $table->date('date');
            $table->string('class_name');
            $table->string('counselor');
            $table->string('church');
            $table->string('district');
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rep_assistance_adv');
    }
};
