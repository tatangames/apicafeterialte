<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entradas_inventario', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'produccion', 'venta']);
            $table->string('descripcion', 500)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('administradores')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entradas_inventario');
    }
};
