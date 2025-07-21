<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('class_member_adventurer', function (Blueprint $table) {
            $table->dropUnique('member_class_unique');
        });
    }

    public function down()
    {
        Schema::table('class_member_adventurer', function (Blueprint $table) {
            $table->unique(['members_adventurer_id', 'club_class_id'], 'member_class_unique');
        });
    }
};
