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
        'orden',
        'activo',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'orden' => 'integer',
        'activo' => 'boolean',
    ];

    public function grupo()
    {
        return $this->belongsTo(GrupoModificador::class, 'grupo_id');
    }
}
