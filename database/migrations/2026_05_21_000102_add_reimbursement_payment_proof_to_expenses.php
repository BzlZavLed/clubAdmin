<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'reimbursement_payment_proof_path')) {
                $table->string('reimbursement_payment_proof_path')->nullable()->after('reimbursement_receipt_validation_checksum');
            }

            if (!Schema::hasColumn('expenses', 'reimbursement_payment_proof_uploaded_at')) {
                $table->timestamp('reimbursement_payment_proof_uploaded_at')->nullable()->after('reimbursement_payment_proof_path');
            }

            if (!Schema::hasColumn('expenses', 'reimbursement_payment_proof_uploaded_by_user_id')) {
                $table->unsignedBigInteger('reimbursement_payment_proof_uploaded_by_user_id')->nullable()->after('reimbursement_payment_proof_uploaded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            foreach ([
                'reimbursement_payment_proof_uploaded_by_user_id',
                'reimbursement_payment_proof_uploaded_at',
                'reimbursement_payment_proof_path',
            ] as $column) {
                if (Schema::hasColumn('expenses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
