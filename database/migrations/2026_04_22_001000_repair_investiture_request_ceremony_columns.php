<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('investiture_requests', 'tentative_investiture_date')) {
                $table->date('tentative_investiture_date')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'approved_investiture_date')) {
                $table->date('approved_investiture_date')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'authorization_person_name')) {
                $table->string('authorization_person_name')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'ceremony_representative_name')) {
                $table->string('ceremony_representative_name')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'date_change_reason')) {
                $table->text('date_change_reason')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'date_change_requested_at')) {
                $table->timestamp('date_change_requested_at')->nullable();
            }

            if (!Schema::hasColumn('investiture_requests', 'date_change_requested_by')) {
                $table->foreignId('date_change_requested_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            if (Schema::hasColumn('investiture_requests', 'date_change_requested_by')) {
                $table->dropConstrainedForeignId('date_change_requested_by');
            }

            foreach ([
                'tentative_investiture_date',
                'approved_investiture_date',
                'authorization_person_name',
                'ceremony_representative_name',
                'date_change_reason',
                'date_change_requested_at',
            ] as $column) {
                if (Schema::hasColumn('investiture_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
