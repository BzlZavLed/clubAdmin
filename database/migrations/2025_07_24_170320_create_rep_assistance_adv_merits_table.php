<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('rep_assistance_adv')->onDelete('cascade');

            $table->boolean('asistencia')->default(false);
            $table->boolean('puntualidad')->default(false);
            $table->boolean('uniforme')->default(false);
            $table->boolean('conductor')->default(false);
            $table->boolean('cuota')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rep_assistance_adv_merits');
    }
};
