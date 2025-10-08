<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_concept_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_concept_id')
                ->constrained('payment_concepts')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Scope types:
            // - club_wide      (applies to the whole club)
            // - class          (specific class_id)
            // - member         (specific member_id)
            // - staff_wide     (all staff in a club)
            // - staff          (specific staff_id)
            $table->enum('scope_type', ['club_wide', 'class', 'member', 'staff_wide', 'staff']);

            // Target IDs (nullable depending on scope_type)
            $table->foreignId('club_id')->nullable()
                ->constrained('clubs')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('class_id')->nullable()
                ->constrained('club_classes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('member_id')->nullable()
                ->constrained('members_adventurers')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('staff_id')->nullable()
                ->constrained('staff_adventurers')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_type', 'club_id', 'class_id', 'member_id', 'staff_id'], 'scope_lookup_idx');

            // Avoid exact duplicates
            $table->unique(
                ['payment_concept_id', 'scope_type', 'club_id', 'class_id', 'member_id', 'staff_id'],
                'unique_concept_scope'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_concept_scopes');
    }
};
