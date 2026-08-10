<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adventurer_induction_requests', function (Blueprint $table) {
            $table->string('last_sent_to_email')->nullable()->after('emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('adventurer_induction_requests', function (Blueprint $table) {
            $table->dropColumn('last_sent_to_email');
        });
    }
};
