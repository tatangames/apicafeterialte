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
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'produccion', 'venta']);
            $table->decimal('cantidad', 10, 4);
            $table->decimal('stock_anterior', 10, 4);
            $table->decimal('stock_resultante', 10, 4);
            $table->string('motivo', 500)->nullable();
            // referencia al documento cabecera
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('referencia_tipo', 50)->nullable(); // 'entrada_inventario', 'orden', 'produccion'
            $table->foreignId('usuario_id')->nullable()->constrained('administradores')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
