<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_member_pathfinder', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('club_class_id');
            $table->string('role')->nullable();
            $table->date('assigned_at')->nullable();
            $table->date('finished_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('club_class_id')->references('id')->on('club_classes')->cascadeOnDelete();
            $table->index(['member_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_member_pathfinder');
    }
};

