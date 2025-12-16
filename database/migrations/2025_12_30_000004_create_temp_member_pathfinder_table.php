<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('temp_member_pathfinder', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('nombre');
            $table->date('dob')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('father_name')->nullable();
            $table->string('father_phone', 50)->nullable();
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temp_member_pathfinder');
    }
};
