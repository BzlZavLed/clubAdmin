<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_concepts', function (Blueprint $table) {
            $table->id();
            $table->string('concept');                          // e.g. "Registration Fee", "Uniform"
            $table->date('payment_expected_by')->nullable();    // due date (optional)

            $table->enum('type', ['mandatory', 'optional'])->default('mandatory');

            // Where the payment goes
            $table->enum('pay_to', ['church_budget', 'club_budget', 'conference', 'reimbursement_to'])
                ->default('club_budget');

            // If pay_to = reimbursement_to, optionally target a payee (polymorphic for flexibility)
            $table->nullableMorphs('payee'); // payee_type, payee_id (could be MemberAdventurer/StaffAdventurer/User)

            // Who created this concept
            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->enum('status', ['active', 'inactive'])->default('active');

            // If concept is meant to be used only by a specific club universe, keep a quick filter (optional)
            $table->foreignId('club_id')->nullable()
                ->constrained('clubs')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
            $table->index(['club_id', 'payment_expected_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_concepts');
    }
};
