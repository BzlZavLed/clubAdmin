<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reimbursement_receipt_token', 64)->nullable()->unique()->after('reimbursement_receipt_path');
            $table->timestamp('reimbursement_receipt_signed_at')->nullable()->after('reimbursement_receipt_token');
            $table->string('reimbursement_receipt_signature_path')->nullable()->after('reimbursement_receipt_signed_at');
            $table->string('reimbursement_receipt_signer_name')->nullable()->after('reimbursement_receipt_signature_path');
            $table->boolean('reimbursement_receipt_acknowledged')->default(false)->after('reimbursement_receipt_signer_name');
            $table->string('reimbursement_receipt_ip', 45)->nullable()->after('reimbursement_receipt_acknowledged');
            $table->text('reimbursement_receipt_user_agent')->nullable()->after('reimbursement_receipt_ip');
            $table->string('reimbursement_receipt_validation_checksum', 128)->nullable()->after('reimbursement_receipt_user_agent');
        });

        DB::table('expenses')
            ->where('pay_to', 'reimbursement_to')
            ->where('status', 'completed')
            ->whereNull('reimbursement_receipt_token')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) {
                DB::table('expenses')
                    ->where('id', $id)
                    ->update(['reimbursement_receipt_token' => Str::random(48)]);
            });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['reimbursement_receipt_token']);
            $table->dropColumn([
                'reimbursement_receipt_token',
                'reimbursement_receipt_signed_at',
                'reimbursement_receipt_signature_path',
                'reimbursement_receipt_signer_name',
                'reimbursement_receipt_acknowledged',
                'reimbursement_receipt_ip',
                'reimbursement_receipt_user_agent',
                'reimbursement_receipt_validation_checksum',
            ]);
        });
    }
};
