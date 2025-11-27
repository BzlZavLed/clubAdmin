<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->string('pay_to');
            $table->string('label')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['club_id', 'pay_to']);
            $table->foreign('club_id')->references('id')->on('clubs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
