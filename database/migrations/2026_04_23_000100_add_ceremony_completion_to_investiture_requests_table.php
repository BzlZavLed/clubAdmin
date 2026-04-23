<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('investiture_requests')) {
            return;
        }

        Schema::table('investiture_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('investiture_requests', 'ceremony_completed_by')) {
                $table->foreignId('ceremony_completed_by')->nullable()->after('date_change_requested_by');
                $table->foreign('ceremony_completed_by', 'ir_ceremony_completed_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('investiture_requests', 'ceremony_completed_at')) {
                $table->timestamp('ceremony_completed_at')->nullable()->after('ceremony_completed_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('investiture_requests')) {
            return;
        }

        Schema::table('investiture_requests', function (Blueprint $table) {
            if (Schema::hasColumn('investiture_requests', 'ceremony_completed_by')) {
                $table->dropForeign('ir_ceremony_completed_by_fk');
                $table->dropColumn('ceremony_completed_by');
            }

            if (Schema::hasColumn('investiture_requests', 'ceremony_completed_at')) {
                $table->dropColumn('ceremony_completed_at');
            }
        });
    }
};
