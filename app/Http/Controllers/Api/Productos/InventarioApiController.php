<?php

namespace App\Http\Controllers\Api\Productos;

use App\Http\Controllers\Controller;
use App\Models\EntradaInventario;
use App\Models\Inventario;
use App\Models\Producto;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventarioApiController extends Controller
{
    // GET /inventario/stock
    public function stock()
    {
        try {
            $productos = Producto::with('inventario')
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(fn($p) => [
                    'producto_id'  => $p->id,
                    'producto'     => $p->nombre,
                    'sku'          => $p->sku,
                    'imagen'       => $p->imagen,
                    'stock_actual' => (float) ($p->inventario->stock_actual ?? 0),
                    'stock_minimo' => (float) ($p->inventario->stock_minimo ?? 0),
                    'bajo_minimo'  => (float) ($p->inventario->stock_actual ?? 0)
                        <= (float) ($p->inventario->stock_minimo ?? 0),
                ]);

            return response()->json($productos);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener stock: ' . $e->getMessage(),
            ], 500);
        }
    }

    // GET /inventario/entradas
    public function listarEntradas()
    {
        try {
            $entradas = EntradaInventario::with(['usuario', 'detalles.producto'])
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->get()
                ->map(fn($e) => [
                    'id'          => $e->id,
                    'fecha'       => $e->fecha->format('d/m/Y'),
                    'tipo'        => $e->tipo,
                    'descripcion' => $e->descripcion,
                    'usuario'     => $e->usuario?->name,
                    'total_items' => $e->detalles->count(),
                    'detalles'    => $e->detalles->map(fn($d) => [
                        'producto_id'      => $d->producto_id,
                        'producto'         => $d->producto->nombre,
                        'sku'              => $d->producto->sku,
                        'cantidad'         => (float) $d->cantidad,
                        'stock_anterior'   => (float) $d->stock_anterior,
                        'stock_resultante' => (float) $d->stock_resultante,
                    ]),
                ]);

            return response()->json($entradas);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener entradas: ' . $e->getMessage(),
            ], 500);
        }
    }

    // POST /inventario/entradas/registrar
    public function registrarEntrada(Request $request)
    {
        $validator = validator($request->all(), [
            'fecha'              => 'required|date',
            'tipo'               => 'required|in:entrada,salida,ajuste,produccion',
            'descripcion'        => 'nullable|string|max:500',
            'items'              => 'required|array|min:1',
            'items.*.producto_id'=> 'required|integer|exists:productos,id',
            'items.*.cantidad'   => 'required|numeric|min:0.0001',
        ], [
            'fecha.required'              => 'La fecha es requerida',
            'tipo.required'               => 'El tipo es requerido',
            'tipo.in'                     => 'Tipo no válido',
            'items.required'              => 'Debes agregar al menos un producto',
            'items.min'                   => 'Debes agregar al menos un producto',
            'items.*.producto_id.required'=> 'Cada línea debe tener un producto',
            'items.*.producto_id.exists'  => 'Uno o más productos no existen',
            'items.*.cantidad.required'   => 'Cada línea debe tener una cantidad',
            'items.*.cantidad.min'        => 'La cantidad debe ser mayor a 0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $entrada = InventarioService::registrarEntrada(
                tipo:        $request->input('tipo'),
                fecha:       $request->input('fecha'),
                items:       $request->input('items'),
                descripcion: $request->input('descripcion'),
                usuarioId:   auth()->id(),
            );

            return response()->json([
                'success' => 1,
                'message' => 'Registro guardado correctamente',
                'id'      => $entrada->id,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al registrar: ' . $e->getMessage(),
            ], 500);
        }
    }

    // GET /inventario/entradas/{id}
    public function mostrarEntrada($id)
    {
        try {
            $entrada = EntradaInventario::with(['usuario', 'detalles.producto'])->find($id);

            if (!$entrada) {
                return response()->json(['success' => 0, 'message' => 'Registro no encontrado'], 404);
            }

            return response()->json([
                'id'          => $entrada->id,
                'fecha'       => $entrada->fecha->format('d/m/Y'),
                'tipo'        => $entrada->tipo,
                'descripcion' => $entrada->descripcion,
                'usuario'     => $entrada->usuario?->name,
                'detalles'    => $entrada->detalles->map(fn($d) => [
                    'producto_id'      => $d->producto_id,
                    'producto'         => $d->producto->nombre,
                    'sku'              => $d->producto->sku,
                    'imagen'           => $d->producto->imagen,
                    'cantidad'         => (float) $d->cantidad,
                    'stock_anterior'   => (float) $d->stock_anterior,
                    'stock_resultante' => (float) $d->stock_resultante,
                ]),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener registro: ' . $e->getMessage(),
            ], 500);
        }
    }
}
