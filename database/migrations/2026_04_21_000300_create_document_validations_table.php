<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_validations', function (Blueprint $table) {
            $table->id();
            $table->string('checksum', 64)->unique();
            $table->string('document_type');
            $table->string('title');
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->json('document_snapshot')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('last_validated_at')->nullable();
            $table->unsignedInteger('validation_count')->default(0);
            $table->timestamps();

            $table->foreign('generated_by_user_id', 'document_validations_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['document_type', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_validations');
    }
};
