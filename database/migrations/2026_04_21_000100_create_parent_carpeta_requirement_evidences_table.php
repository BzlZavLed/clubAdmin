<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_carpeta_requirement_evidences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('union_carpeta_requirement_id');
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->string('evidence_type')->default('physical_only');
            $table->text('text_value')->nullable();
            $table->string('file_path')->nullable();
            $table->boolean('physical_completed')->default(false);
            $table->string('status')->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'union_carpeta_requirement_id'], 'parent_carpeta_evidence_member_requirement_unique');
            $table->foreign('member_id', 'parent_carpeta_evidence_member_fk')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('union_carpeta_requirement_id', 'parent_carpeta_evidence_req_fk')->references('id')->on('union_carpeta_requirements')->cascadeOnDelete();
            $table->foreign('submitted_by_user_id', 'parent_carpeta_evidence_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_carpeta_requirement_evidences');
    }
};
