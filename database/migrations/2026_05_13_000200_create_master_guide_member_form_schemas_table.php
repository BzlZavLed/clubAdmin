<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_guide_member_form_schemas')) {
            return;
        }

        Schema::create('master_guide_member_form_schemas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->json('schema_json')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('club_id', 'mg_schema_club_fk')->references('id')->on('clubs')->cascadeOnDelete();
            $table->foreign('updated_by', 'mg_schema_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique('club_id', 'mg_schema_club_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_guide_member_form_schemas');
    }
};
