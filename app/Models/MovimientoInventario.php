<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'producto_id',
        'tipo',
        'cantidad',
        'stock_anterior',
        'stock_resultante',
        'motivo',
        'referencia_id',
        'referencia_tipo',
        'usuario_id',
    ];

    protected $casts = [
        'cantidad'         => 'decimal:4',
        'stock_anterior'   => 'decimal:4',
        'stock_resultante' => 'decimal:4',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
