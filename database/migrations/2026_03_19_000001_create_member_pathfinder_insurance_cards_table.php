<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_pathfinder_insurance_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_pathfinder_id');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('member_pathfinder_id')
                ->references('id')
                ->on('members_pathfinders')
                ->cascadeOnDelete();
            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->unique('member_pathfinder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_pathfinder_insurance_cards');
    }
};
