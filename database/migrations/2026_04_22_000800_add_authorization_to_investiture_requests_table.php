<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            $table->foreignId('authorized_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable()->after('authorized_by');
        });
    }

    public function down(): void
    {
        Schema::table('investiture_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authorized_by');
            $table->dropColumn('authorized_at');
        });
    }
};
