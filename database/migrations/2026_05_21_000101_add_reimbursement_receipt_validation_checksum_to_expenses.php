<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('expenses', 'reimbursement_receipt_validation_checksum')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reimbursement_receipt_validation_checksum', 128)
                ->nullable()
                ->after('reimbursement_receipt_user_agent');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('expenses', 'reimbursement_receipt_validation_checksum')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('reimbursement_receipt_validation_checksum');
        });
    }
};
