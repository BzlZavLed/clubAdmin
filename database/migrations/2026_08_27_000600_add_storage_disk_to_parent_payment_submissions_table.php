<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parent_payment_submissions', function (Blueprint $table) {
            $table->string('receipt_image_disk', 32)->nullable()->after('receipt_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('parent_payment_submissions', function (Blueprint $table) {
            $table->dropColumn('receipt_image_disk');
        });
    }
};
