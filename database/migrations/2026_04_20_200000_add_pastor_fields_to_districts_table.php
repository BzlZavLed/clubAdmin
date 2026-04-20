<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->string('pastor_name')->nullable()->after('name');
            $table->string('pastor_email')->nullable()->after('pastor_name');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn(['pastor_name', 'pastor_email']);
        });
    }
};
