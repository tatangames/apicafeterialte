<?php

namespace App\Exceptions;

/**
 * La operación necesita un permiso que el usuario no tiene (ej. un descuento
 * mayor al máximo o una anulación): solo la puede hacer el administrador o
 * alguien con ese permiso. Se responde 403 con status SIN_PERMISO y el permiso.
 */
class AutorizacionRequeridaException extends NegocioException
{
    public function __construct(public readonly string $permiso, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
