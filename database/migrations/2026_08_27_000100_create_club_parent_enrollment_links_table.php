<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_parent_enrollment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'revoked_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('secure_enrollment_link_id')->nullable()->after('status')->constrained('club_parent_enrollment_links')->nullOnDelete();
            $table->timestamp('enrollment_confirmed_at')->nullable()->after('secure_enrollment_link_id');
            $table->foreignId('enrollment_confirmed_by')->nullable()->after('enrollment_confirmed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('secure_enrollment_link_id')->nullable()->after('status')->constrained('club_parent_enrollment_links')->nullOnDelete();
            $table->timestamp('enrollment_confirmed_at')->nullable()->after('secure_enrollment_link_id');
            $table->foreignId('enrollment_confirmed_by')->nullable()->after('enrollment_confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_confirmed_by');
            $table->dropColumn('enrollment_confirmed_at');
            $table->dropConstrainedForeignId('secure_enrollment_link_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_confirmed_by');
            $table->dropColumn('enrollment_confirmed_at');
            $table->dropConstrainedForeignId('secure_enrollment_link_id');
        });

        Schema::dropIfExists('club_parent_enrollment_links');
    }
};
