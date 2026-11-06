<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modificador extends Model
{
    protected $table = 'modificadores';

    protected $fillable = [
        'grupo_id',
        'nombre',
        'precio',
        'producto_id',
        'cantidad',
        'unidad_medida_id',
        'orden',
        'activo',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'cantidad' => 'decimal:4',
        'orden' => 'integer',
        'activo' => 'boolean',
    ];

    public function grupo()
    {
        return $this->belongsTo(GrupoModificador::class, 'grupo_id');
    }

    // Insumo que se descuenta al vender la opción
    public function insumo()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidad()
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }
}
