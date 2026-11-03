<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDetalle extends Model
{
    protected $table = 'ventas_detalle';

    protected $fillable = [
        'venta_id',
        'producto_id',
        'nombre_producto',
        'unidad',
        'cantidad',
        'precio_unitario',
        'descuento_tipo',
        'descuento_valor',
        'descuento',
        'subtotal',
        'costo_unitario',
        'consumo_interno',
        'cantidad_devuelta',
    ];

    protected $casts = [
        'cantidad'          => 'decimal:4',
        'precio_unitario'   => 'decimal:2',
        'descuento_valor'   => 'decimal:2',
        'descuento'         => 'decimal:2',
        'subtotal'          => 'decimal:2',
        'costo_unitario'    => 'decimal:4',
        'consumo_interno'   => 'boolean',
        'cantidad_devuelta' => 'decimal:4',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function cantidadDisponibleParaDevolver(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 4);
    }
}
