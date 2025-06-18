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
        Schema::create('club_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->integer('class_order');
            $table->string('class_name');
            $table->unsignedBigInteger('assigned_staff_id')->nullable();
            $table->timestamps();
    
            $table->foreign('club_id')->references('id')->on('clubs')->onDelete('cascade');
            $table->foreign('assigned_staff_id')->references('id')->on('staff_adventurers')->onDelete('set null');
        });;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('club_classes');
    }
};
