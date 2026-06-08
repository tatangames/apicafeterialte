<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntradaInventario extends Model
{
    protected $table = 'entradas_inventario';

    protected $fillable = [
        'fecha',
        'tipo',
        'descripcion',
        'usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function detalles()
    {
        return $this->hasMany(EntradaInventarioDetalle::class, 'entrada_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
