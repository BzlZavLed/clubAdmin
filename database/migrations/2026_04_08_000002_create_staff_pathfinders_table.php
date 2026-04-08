<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pathfinders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('source_temp_staff_pathfinder_id')->nullable()->unique();
            $table->string('staff_name');
            $table->date('staff_dob')->nullable();
            $table->integer('staff_age')->nullable();
            $table->string('staff_email')->nullable();
            $table->string('staff_phone', 50)->nullable();
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        if (!Schema::hasTable('temp_staff_pathfinder')) {
            return;
        }

        $legacyRows = DB::table('temp_staff_pathfinder')->orderBy('id')->get();

        foreach ($legacyRows as $row) {
            $staffId = $row->staff_id;

            if (!$staffId && Schema::hasTable('staff')) {
                $staffId = DB::table('staff')
                    ->where('club_id', $row->club_id)
                    ->where('user_id', $row->user_id)
                    ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
                    ->value('id');
            }

            $newId = DB::table('staff_pathfinders')->insertGetId([
                'club_id' => $row->club_id,
                'staff_id' => $staffId,
                'user_id' => $row->user_id ?? null,
                'source_temp_staff_pathfinder_id' => $row->id,
                'staff_name' => $row->staff_name,
                'staff_dob' => $row->staff_dob,
                'staff_age' => $row->staff_age,
                'staff_email' => $row->staff_email,
                'staff_phone' => $row->staff_phone,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);

            if ($staffId) {
                DB::table('staff')
                    ->where('id', $staffId)
                    ->update([
                        'type' => 'pathfinders',
                        'id_data' => $newId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_pathfinders');
    }
};
