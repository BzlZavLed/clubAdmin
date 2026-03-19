<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->boolean('reusable')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_concepts', function (Blueprint $table) {
            $table->dropColumn('reusable');
        });
    }
};
