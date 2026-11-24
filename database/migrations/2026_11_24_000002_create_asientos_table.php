<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained()->cascadeOnDelete();
            $table->string('fila', 2);
            $table->unsignedSmallInteger('numero');
            // libre | reservado  (vendido llegará con el servicio de pago)
            $table->string('estado')->default('libre');
            $table->string('reservado_por')->nullable();
            $table->timestamp('hold_hasta')->nullable();
            $table->timestamps();

            $table->unique(['evento_id', 'fila', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asientos');
    }
};
