<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Producto extends Model
{
    use SoftDeletes;

    const TIPO_BIEN          = 'bien';
    const TIPO_SERVICIO      = 'servicio';
    const TIPO_BIEN_SERVICIO = 'bien_servicio';

    protected $fillable = [
        'sku',
        'nombre',
        'descripcion',
        'imagen',
        'tipo',
        'costo_unitario',
        'unidad_medida_id',
        'activo',
    ];

    protected $casts = [
        'costo_unitario' => 'decimal:2',
        'activo'         => 'boolean',
    ];

    // ─── Relaciones ───────────────────────────────────────────────
    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(
            Categoria::class,
            'categoria_producto',
            'producto_id',
            'categoria_id'
        );
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────
    public function manejaBien(): bool
    {
        return in_array($this->tipo, [self::TIPO_BIEN, self::TIPO_BIEN_SERVICIO]);
    }

    public function manejaServicio(): bool
    {
        return in_array($this->tipo, [self::TIPO_SERVICIO, self::TIPO_BIEN_SERVICIO]);
    }

    public function inventario()
    {
        return $this->hasOne(Inventario::class);
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class);
    }
}
