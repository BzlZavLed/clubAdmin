<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('serpapi_usage_monthly', function (Blueprint $table) {
            $table->id();
            $table->date('usage_month');
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('last_called_at')->nullable();
            $table->timestamps();

            $table->unique('usage_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpapi_usage_monthly');
    }
};
