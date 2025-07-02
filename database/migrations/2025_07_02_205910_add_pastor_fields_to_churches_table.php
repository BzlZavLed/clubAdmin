<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->string('pastor_name')->nullable()->after('email');
            $table->string('pastor_email')->nullable()->after('pastor_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->dropColumn(['pastor_name', 'pastor_email']);
        });
    }
};
