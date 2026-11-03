<?php

namespace Tests;

use App\Models\Administrador;
use Database\Seeders\ConfiguracionNegocioSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, ConfiguracionNegocioSeeder::class]);
    }

    /**
     * Crea un usuario activo con el rol indicado (admin, cajero, encargado_inventario, editor).
     */
    protected function crearUsuario(string $rol = 'admin', array $atributos = []): Administrador
    {
        static $n = 0;
        $n++;

        $usuario = Administrador::create(array_merge([
            'nombre'   => "Usuario {$n}",
            'email'    => "usuario{$n}@test.com",
            'password' => 'secreto123',
            'estado'   => true,
        ], $atributos));

        $usuario->assignRole($rol);

        return $usuario;
    }

    /**
     * Autentica las peticiones siguientes con un usuario del rol indicado.
     */
    protected function actuarComo(string $rol = 'admin', array $atributos = []): Administrador
    {
        $usuario = $this->crearUsuario($rol, $atributos);
        Sanctum::actingAs($usuario, ['*'], 'sanctum');

        return $usuario;
    }
}
