<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_master_guides')) {
            return;
        }

        Schema::create('staff_master_guides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('staff_name');
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('emergency_contact_email')->nullable();
            $table->json('custom_fields_json')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('club_id', 'mg_staff_club_fk')->references('id')->on('clubs')->cascadeOnDelete();
            $table->foreign('staff_id', 'mg_staff_staff_fk')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('user_id', 'mg_staff_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique('staff_id', 'mg_staff_staff_unique');
            $table->index(['club_id', 'status'], 'mg_staff_club_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_master_guides');
    }
};
