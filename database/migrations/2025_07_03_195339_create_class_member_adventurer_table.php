<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_member_adventurer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('members_adventurer_id')->constrained()->onDelete('cascade');
            $table->foreignId('club_class_id')->constrained()->onDelete('cascade');
        
            $table->string('role')->nullable(); // e.g. 'student', 'assistant'
            $table->date('assigned_at')->nullable(); // when the member was assigned
            $table->date('finished_at')->nullable(); // when the class was completed
        
            $table->boolean('active')->default(true); // true = current class, false = completed or inactive
        
            $table->timestamps();
        
            $table->unique(['members_adventurer_id', 'club_class_id', 'active'], 'member_class_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_member_adventurer');
    }
};
