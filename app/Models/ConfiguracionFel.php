<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de facturación electrónica (DTE El Salvador). Una sola fila.
 * Las contraseñas y el token nunca se devuelven al frontend (ver $hidden).
 */
class ConfiguracionFel extends Model
{
    protected $table = 'configuracion_fel';

    protected $fillable = [
        'activo', 'ambiente', 'proveedor',
        'api_url', 'api_usuario', 'api_password', 'firmador_url', 'clave_privada',
        'token', 'token_expira_at',
        'nit', 'nrc', 'razon_social', 'nombre_comercial', 'actividades',
        'tipo_establecimiento', 'cod_establecimiento_mh', 'cod_establecimiento',
        'cod_punto_venta_mh', 'cod_punto_venta',
        'departamento', 'municipio', 'distrito', 'direccion', 'telefono', 'correo',
        'tipos_dte',
        'ultima_prueba_at', 'ultima_prueba_ok', 'ultima_prueba_mensaje',
    ];

    protected $hidden = ['api_password', 'clave_privada', 'token'];

    protected $appends = ['tiene_api_password', 'tiene_clave_privada'];

    protected $casts = [
        'activo'           => 'boolean',
        'api_password'     => 'encrypted',
        'clave_privada'    => 'encrypted',
        'token'            => 'encrypted',
        'token_expira_at'  => 'datetime',
        'actividades'      => 'array',
        'tipos_dte'        => 'array',
        'ultima_prueba_at' => 'datetime',
        'ultima_prueba_ok' => 'boolean',
    ];

    public static function actual(): self
    {
        return static::query()->first() ?? static::create([
            'activo'       => false,
            'ambiente'     => '00',
            'proveedor'    => 'hacienda',
            'firmador_url' => config('fel.firmador_url_defecto'),
            'tipos_dte'    => ['01'],
            'actividades'  => [],
        ]);
    }

    public function getTieneApiPasswordAttribute(): bool
    {
        return filled($this->attributes['api_password'] ?? null);
    }

    public function getTieneClavePrivadaAttribute(): bool
    {
        return filled($this->attributes['clave_privada'] ?? null);
    }

    /** URL base de la API del MH según el ambiente configurado */
    public function urlHacienda(): string
    {
        return config('fel.ambientes.' . $this->ambiente . '.url');
    }
}
