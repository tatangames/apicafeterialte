<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use App\Services\InventarioService;

class PosApiController extends Controller
{
    // GET /pos/catalogo — todo lo que el POS necesita para vender (se guarda en el dispositivo)
    public function catalogo()
    {
        $productos = Producto::with(['categorias:id,area_preparacion_id', 'categorias.area:id,nombre,activo', 'inventario', 'unidadMedida'])
            ->where('activo', true)
            ->where('se_vende', true)
            ->orderBy('orden_pos')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Producto $p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'sku'              => $p->sku,
                'codigo_barras'    => $p->codigo_barras,
                'imagen'           => $p->imagen,
                'color'            => $p->color_pos,
                'precio'           => (float) $p->precio_venta,
                'categorias'       => $p->categorias->pluck('id'),
                'unidad'           => $p->unidadMedida?->abreviatura,
                'permite_fraccion' => $p->permite_fraccion,
                'controla_stock'   => $p->controla_stock,
                'consumo_interno'  => $p->consumo_interno,
                // Área de su comanda (null = comanda general); sirve para imprimir desde el navegador
                'area'             => $p->categorias->map(fn ($c) => $c->area)->first(fn ($a) => $a && $a->activo)?->nombre,
                'stock'            => $p->controla_stock ? (float) ($p->inventario->stock_actual ?? 0) : null,
                'bajo_minimo'      => $p->controla_stock && InventarioService::enAlerta(
                    (float) ($p->inventario->stock_actual ?? 0),
                    (float) ($p->inventario->stock_minimo ?? 0)
                ),
            ]);

        // Solo categorías que tienen productos a la venta
        $usadas = $productos->pluck('categorias')->flatten()->unique();
        $categorias = Categoria::where('estado', true)->whereIn('id', $usadas)->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json([
            'success'      => 1,
            'productos'    => $productos,
            'categorias'   => $categorias,
            'generado_at'  => now()->toIso8601String(),
        ]);
    }
}
