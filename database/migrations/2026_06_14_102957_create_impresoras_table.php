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
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 200);               // Nombre amigable ej: "Impresora Cocina"
            $table->string('ip_impresora', 50);         // IP de la impresora térmica
            $table->string('ip_servidor', 50);          // IP del servidor principal
            $table->enum('tipo_impresion', ['escpos'])         // Por ahora solo ESC/POS
            ->default('escpos');
            $table->enum('cash_drawer', ['ninguno', 'pin2', 'pin5'])->default('ninguno'); // PIN NO.2, PIN NO.5 o ninguno
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
        Schema::dropIfExists('impresoras');
    }
};
