<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parent_payment_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('parent_payment_submissions', 'club_receipt_email')) {
                $table->string('club_receipt_email')->nullable()->after('receipt_image_path');
            }
            if (!Schema::hasColumn('parent_payment_submissions', 'club_receipt_email_status')) {
                $table->string('club_receipt_email_status', 32)->nullable()->after('club_receipt_email');
            }
            if (!Schema::hasColumn('parent_payment_submissions', 'club_receipt_emailed_at')) {
                $table->timestamp('club_receipt_emailed_at')->nullable()->after('club_receipt_email_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parent_payment_submissions', function (Blueprint $table) {
            foreach (['club_receipt_emailed_at', 'club_receipt_email_status', 'club_receipt_email'] as $column) {
                if (Schema::hasColumn('parent_payment_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
