<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_club_settlements', function (Blueprint $table) {
            $table->string('deposit_proof_path')->nullable()->after('notes');
            $table->string('deposit_proof_original_name')->nullable()->after('deposit_proof_path');
            $table->timestamp('deposit_proof_uploaded_at')->nullable()->after('deposit_proof_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('event_club_settlements', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_proof_path',
                'deposit_proof_original_name',
                'deposit_proof_uploaded_at',
            ]);
        });
    }
};
