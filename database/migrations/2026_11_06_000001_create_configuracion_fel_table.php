<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FACTURACIÓN ELECTRÓNICA (DTE - Ministerio de Hacienda de El Salvador)
     *
     * Una sola fila con los datos del emisor y las credenciales para transmitir.
     * Queda lista para cuando el negocio se registre en Hacienda; mientras `activo`
     * sea false, ninguna venta intenta generar DTE.
     * Los códigos siguen los catálogos del MH (CAT-009, CAT-012, CAT-013, CAT-019, CAT-002).
     */
    public function up(): void
    {
        Schema::create('configuracion_fel', function (Blueprint $table) {
            $table->id();
            $table->boolean('activo')->default(false);
            $table->string('ambiente', 2)->default('00');            // 00 = pruebas, 01 = producción
            $table->string('proveedor', 20)->default('hacienda');    // hacienda (directo) | externo (certificador)

            // Credenciales de transmisión (las contraseñas se guardan cifradas con APP_KEY)
            $table->string('api_url', 300)->nullable();              // solo proveedor externo
            $table->string('api_usuario', 100)->nullable();          // con Hacienda directo es el NIT
            $table->text('api_password')->nullable();
            $table->string('firmador_url', 300)->nullable();         // firmador del MH (Docker), ej. http://localhost:8113/firmardocumento/
            $table->text('clave_privada')->nullable();               // contraseña de la llave privada del certificado
            $table->text('token')->nullable();                       // token de sesión del MH (vence a las 24 h en producción)
            $table->timestamp('token_expira_at')->nullable();

            // Datos del emisor
            $table->string('nit', 14)->nullable();
            $table->string('nrc', 8)->nullable();
            $table->string('razon_social', 250)->nullable();
            $table->string('nombre_comercial', 150)->nullable();
            $table->json('actividades')->nullable();                 // [{codigo, descripcion}] la primera es la principal

            // Establecimiento y dirección
            $table->string('tipo_establecimiento', 2)->nullable();   // CAT-009
            $table->string('cod_establecimiento_mh', 4)->nullable(); // asignado por Hacienda
            $table->string('cod_establecimiento', 10)->nullable();   // código interno
            $table->string('cod_punto_venta_mh', 4)->nullable();
            $table->string('cod_punto_venta', 15)->nullable();
            $table->string('departamento', 2)->nullable();           // CAT-012
            $table->string('municipio', 2)->nullable();              // CAT-013 (municipios desde 2024)
            $table->string('distrito', 100)->nullable();
            $table->string('direccion', 200)->nullable();            // complemento
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();

            // Documentos que se emitirán (CAT-002): 01 factura, 03 crédito fiscal, ...
            $table->json('tipos_dte')->nullable();

            // Resultado de la última prueba de conexión
            $table->timestamp('ultima_prueba_at')->nullable();
            $table->boolean('ultima_prueba_ok')->nullable();
            $table->string('ultima_prueba_mensaje', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_fel');
    }
};
