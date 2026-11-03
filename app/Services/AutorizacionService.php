<?php

namespace App\Services;

use App\Exceptions\AutorizacionRequeridaException;
use App\Models\Administrador;

class AutorizacionService
{
    /**
     * Devuelve quién autoriza una operación que exige $permiso: el propio usuario
     * si tiene el permiso. Si no lo tiene, la operación no se hace (no hay PIN:
     * cada quien entra con su usuario y el administrador tiene acceso total).
     */
    public static function autorizar(Administrador $usuario, string $permiso, string $mensaje): Administrador
    {
        if ($usuario->can($permiso)) {
            return $usuario;
        }

        throw new AutorizacionRequeridaException($permiso, $mensaje);
    }
}
