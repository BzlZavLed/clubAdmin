<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->string('email_uid')->nullable()->after('id');
        });

        DB::table('mail_delivery_logs')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(function ($row): void {
                DB::table('mail_delivery_logs')
                    ->where('id', $row->id)
                    ->update(['email_uid' => 'mail_' . strtolower((string) Str::ulid())]);
            });

        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->unique('email_uid');
        });
    }

    public function down(): void
    {
        Schema::table('mail_delivery_logs', function (Blueprint $table) {
            $table->dropUnique(['email_uid']);
            $table->dropColumn('email_uid');
        });
    }
};
