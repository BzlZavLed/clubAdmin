<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_reimbursement_payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'name']);
            $table->index(['club_id', 'email']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('reimbursement_payee_id')
                ->nullable()
                ->after('reimbursed_to')
                ->constrained('finance_reimbursement_payees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reimbursement_payee_id');
        });

        Schema::dropIfExists('finance_reimbursement_payees');
    }
};
