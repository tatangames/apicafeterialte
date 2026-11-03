<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenItem extends Model
{
    protected $table = 'ordenes_items';

    protected $fillable = [
        'orden_id',
        'ticket_id',
        'producto_id',
        'nombre_producto',
        'cantidad',
        'precio_base',
        'modificadores',
        'precio_unitario',
        'notas',
        'estado',
        'area_id',
        'comanda_id',
        'venta_id',
        'usuario_id',
        'cancelado_por',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precio_base' => 'decimal:2',
        'modificadores' => 'array',
        'precio_unitario' => 'decimal:2',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function ticket()
    {
        return $this->belongsTo(OrdenTicket::class, 'ticket_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function area()
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_id');
    }

    public function comanda()
    {
        return $this->belongsTo(Comanda::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    // Lo que va al ticket y la comanda: «Frappuccino (caramelo, deslactosada)»
    public function descripcion(): string
    {
        $extras = collect($this->modificadores ?? [])->pluck('opcion')->filter()->join(', ');
        return $this->nombre_producto . ($extras ? " ({$extras})" : '');
    }

    public function subtotal(): float
    {
        return round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }
}
