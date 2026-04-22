<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            $table->date('tentative_investiture_date')->nullable()->after('director_notes');
            $table->date('approved_investiture_date')->nullable()->after('tentative_investiture_date');
            $table->string('authorization_person_name')->nullable()->after('authorized_at');
            $table->string('ceremony_representative_name')->nullable()->after('authorization_person_name');
            $table->text('date_change_reason')->nullable()->after('ceremony_representative_name');
            $table->timestamp('date_change_requested_at')->nullable()->after('date_change_reason');
            $table->foreignId('date_change_requested_by')->nullable()->after('date_change_requested_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('date_change_requested_by');
            $table->dropColumn([
                'tentative_investiture_date',
                'approved_investiture_date',
                'authorization_person_name',
                'ceremony_representative_name',
                'date_change_reason',
                'date_change_requested_at',
            ]);
        });
    }
};
