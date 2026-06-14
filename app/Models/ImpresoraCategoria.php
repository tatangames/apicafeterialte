<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ImpresoraCategoria extends Pivot
{
    protected $table = 'impresoras_categorias';

    public $timestamps = false;
}
