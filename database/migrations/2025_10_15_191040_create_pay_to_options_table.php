<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pay_to_options', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('club_id')->nullable(); // null = global
            $table->string('value')->index();  // e.g. 'club_budget'
            $table->string('label');           // e.g. 'Club budget'
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['club_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_to_options');
    }
};

