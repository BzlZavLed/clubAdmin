<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('investiture_requests', 'ceremony_representative_email')) {
                $table->string('ceremony_representative_email')->nullable()->after('ceremony_representative_name');
            }

            if (!Schema::hasColumn('investiture_requests', 'ceremony_representative_phone')) {
                $table->string('ceremony_representative_phone')->nullable()->after('ceremony_representative_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            foreach (['ceremony_representative_email', 'ceremony_representative_phone'] as $column) {
                if (Schema::hasColumn('investiture_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
