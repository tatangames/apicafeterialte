<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrupoModificador extends Model
{
    protected $table = 'grupos_modificadores';

    protected $fillable = [
        'nombre',
        'obligatorio',
        'multiple',
        'activo',
    ];

    protected $casts = [
        'obligatorio' => 'boolean',
        'multiple' => 'boolean',
        'activo' => 'boolean',
    ];

    public function opciones()
    {
        return $this->hasMany(Modificador::class, 'grupo_id')->orderBy('orden')->orderBy('id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'producto_grupo_modificador', 'grupo_id', 'producto_id');
    }
}
