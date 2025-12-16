<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('temp_staff_pathfinder', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('staff_name');
            $table->date('staff_dob')->nullable();
            $table->integer('staff_age')->nullable();
            $table->string('staff_email')->nullable();
            $table->string('staff_phone', 50)->nullable();
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temp_staff_pathfinder');
    }
};
