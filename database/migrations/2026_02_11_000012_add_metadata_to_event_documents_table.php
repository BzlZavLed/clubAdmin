<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_documents', function (Blueprint $table) {
            $table->string('doc_type')->nullable()->after('type');
            $table->foreignId('member_id')->nullable()->after('doc_type')->constrained('members')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->after('member_id')->constrained('staff')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->after('staff_id')->constrained('users')->nullOnDelete();
            $table->string('status')->default('active')->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('event_documents', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropForeign(['staff_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['doc_type', 'member_id', 'staff_id', 'parent_id', 'status']);
        });
    }
};
