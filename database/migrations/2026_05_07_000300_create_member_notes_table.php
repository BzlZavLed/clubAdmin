<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('member_notes')) {
            Schema::create('member_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject')->nullable();
                $table->text('body');
                $table->string('context')->default('general');
                $table->string('color', 20)->default('yellow');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['member_id', 'created_at'], 'member_notes_member_created_idx');
                $table->index(['district_id', 'created_at'], 'member_notes_district_created_idx');
            });
        }

        if (!Schema::hasTable('member_pastoral_care') || !Schema::hasColumn('member_pastoral_care', 'notes')) {
            return;
        }

        DB::table('member_pastoral_care')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->orderBy('id')
            ->get()
            ->each(function ($care) {
                $alreadyBackfilled = DB::table('member_notes')
                    ->where('member_id', $care->member_id)
                    ->where('context', 'pastoral_care')
                    ->where('body', $care->notes)
                    ->exists();

                if ($alreadyBackfilled) {
                    return;
                }

                DB::table('member_notes')->insert([
                    'member_id' => $care->member_id,
                    'district_id' => $care->district_id,
                    'created_by' => $care->updated_by,
                    'updated_by' => $care->updated_by,
                    'subject' => 'Nota pastoral previa',
                    'body' => $care->notes,
                    'context' => 'pastoral_care',
                    'color' => 'yellow',
                    'created_at' => $care->updated_at ?? now(),
                    'updated_at' => $care->updated_at ?? now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notes');
    }
};
