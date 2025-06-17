<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('club_name');
            $table->string('church_name');
            $table->string('director_name');
            $table->date('creation_date')->nullable();
            $table->string('pastor_name')->nullable();
            $table->string('conference_name')->nullable();
            $table->string('conference_region')->nullable();
            $table->enum('club_type', ['adventurers', 'pathfinders', 'master_guide']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
