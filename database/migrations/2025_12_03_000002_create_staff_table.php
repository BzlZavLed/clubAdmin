<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // adventurers, pathfinders, etc.
            $table->unsignedBigInteger('id_data'); // links to legacy table row
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('assigned_class')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->cascadeOnDelete();
            $table->foreign('assigned_class')->references('id')->on('club_classes')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
