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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100)->unique()->nullable(); // auto-generado si viene vacío
            $table->string('nombre', 300);
            $table->text('descripcion')->nullable();
            $table->string('imagen', 300)->nullable(); // path del archivo
            $table->enum('tipo', ['bien', 'servicio', 'bien_servicio']);
            $table->decimal('costo_unitario', 10, 2)->default(0);
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
