<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnidadesMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $fillable = [
        'nombre',
        'estado',
    ];
}
