<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('church_invite_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id');
            $table->string('code')->unique();
            $table->unsignedInteger('uses_left')->nullable(); // null = unlimited
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('church_id')->references('id')->on('churches')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_invite_codes');
    }
};
