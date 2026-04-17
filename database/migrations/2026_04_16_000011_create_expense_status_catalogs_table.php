<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_status_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('expense_status_catalogs')->insert([
            [
                'id' => 1,
                'status' => 'working',
                'name' => 'En proceso',
                'description' => 'El gasto fue registrado, pero aun no tiene recibo final adjunto.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'status' => 'completed',
                'name' => 'Completado',
                'description' => 'El gasto ya fue completado y cuenta con su soporte correspondiente.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'status' => 'pending_reimbursement',
                'name' => 'Reembolso pendiente',
                'description' => 'El club tiene pendiente reembolsar este monto cuando haya fondos disponibles.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_status_catalogs');
    }
};
