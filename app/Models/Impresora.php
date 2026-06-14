<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Impresora extends Model
{
    use SoftDeletes;

    protected $table = 'impresoras';

    protected $fillable = [
        'alias',
        'ip_impresora',
        'ip_servidor',
        'tipo_impresion',
        'cash_drawer',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(
            Categoria::class,
            'impresoras_categorias',  // tu tabla pivote
            'impresora_id',
            'categoria_id'
        );
    }
}
