<?php

namespace App\Http\Controllers\Api\Productos;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductosApiController extends Controller
{
    public function registrarProducto(Request $request)
    {
        // ── Validación ─────────────────────────────────────────
        $validator = validator($request->all(), [
            'sku'                 => 'nullable|string|max:100|unique:productos,sku',
            'nombre'              => 'required|string|max:300',
            'descripcion'         => 'nullable|string',
            'imagen'              => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'tipo'                => 'required|in:bien,servicio,bien_servicio',
            'costo_unitario'      => 'required|numeric|min:0',
            'unidad_medida_id'    => 'required|integer|exists:unidades_medida,id',
            'activo'              => 'nullable|boolean',
            'categorias'          => 'nullable|array',
            'categorias.*'        => 'integer|exists:categorias,id',
        ], [
            'nombre.required'           => 'El nombre es requerido',
            'tipo.required'             => 'El tipo es requerido',
            'tipo.in'                   => 'El tipo no es válido',
            'costo_unitario.required'   => 'El costo unitario es requerido',
            'costo_unitario.numeric'    => 'El costo unitario debe ser numérico',
            'unidad_medida_id.required' => 'La unidad de medida es requerida',
            'unidad_medida_id.exists'   => 'La unidad de medida no existe',
            'sku.unique'                => 'El SKU ya está registrado',
            'imagen.image'              => 'El archivo debe ser una imagen válida',
            'imagen.mimes'              => 'La imagen debe ser JPG o PNG',
            'imagen.max'                => 'La imagen debe pesar menos de 5MB',
            'categorias.*.exists'       => 'Una o más categorías no existen',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ── Guardado transaccional ─────────────────────────────
        DB::beginTransaction();
        try {

            // 1) Guardar la imagen (directa, sin procesar)
            $nombreImagen = null;
            if ($request->hasFile('imagen')) {
                $foto         = $request->file('imagen');
                $nombreImagen = time() . '_' . Str::random(15) . '.' . $foto->getClientOriginalExtension();
                $foto->move(Storage::disk('archivos')->path(''), $nombreImagen);
            }

            // 2) Crear el producto
            $producto = new Producto();
            $producto->sku                 = $request->input('sku') ?: $this->generarSku();
            $producto->nombre              = $request->input('nombre');
            $producto->descripcion         = $request->input('descripcion');
            $producto->imagen              = $nombreImagen;
            $producto->tipo                = $request->input('tipo');
            $producto->costo_unitario      = $request->input('costo_unitario', 0);
            $producto->unidad_medida_id    = $request->input('unidad_medida_id');
            $producto->activo              = $request->input('activo', true);
            $producto->save();

            // 3) Asignar categorías (tabla categoria_producto)
            $categorias = $request->input('categorias', []);
            if (!empty($categorias)) {
                $producto->categorias()->sync($categorias);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Producto registrado correctamente',
                'id'      => $producto->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Limpieza: si falló después de subir la imagen, la eliminamos
            if (!empty($nombreImagen) && Storage::disk('archivos')->exists($nombreImagen)) {
                Storage::disk('archivos')->delete($nombreImagen);
            }

            return response()->json([
                'success' => 0,
                'message' => 'Error al registrar el producto: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function generarSku(): string
    {
        do {
            $sku = 'PRD-' . strtoupper(Str::random(8));
        } while (Producto::where('sku', $sku)->exists());

        return $sku;
    }


    public function tablaProductos()
    {
        try {
            $productos = Producto::with(['unidadMedida', 'categorias'])
                ->orderByDesc('id')
                ->get()
                ->map(function ($producto) {
                    return [
                        'id'             => $producto->id,
                        'sku'            => $producto->sku,
                        'nombre'         => $producto->nombre,
                        'descripcion'    => $producto->descripcion,
                        'imagen'         => $producto->imagen,
                        'tipo'           => $producto->tipo,
                        'costo_unitario' => $producto->costo_unitario,
                        'activo'         => (bool) $producto->activo,
                        'unidad_medida'  => $producto->unidadMedida?->nombre,
                        'categorias'     => $producto->categorias->pluck('nombre')->toArray(),
                    ];
                });

            return response()->json($productos);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener los productos: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function mostrarProducto($id)
    {
        try {
            $producto = Producto::with(['unidadMedida', 'categorias'])->find($id);

            if (!$producto) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Producto no encontrado',
                ], 404);
            }

            return response()->json([
                'id'               => $producto->id,
                'sku'              => $producto->sku,
                'nombre'           => $producto->nombre,
                'descripcion'      => $producto->descripcion,
                'imagen'           => $producto->imagen,
                'tipo'             => $producto->tipo,
                'costo_unitario'   => $producto->costo_unitario,
                'unidad_medida_id' => $producto->unidad_medida_id,
                'activo'           => (bool) $producto->activo,
                'categorias'       => $producto->categorias->pluck('id')->toArray(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener el producto: ' . $e->getMessage(),
            ], 500);
        }
    }




    public function actualizarProducto(Request $request, $id)
    {
        $producto = Producto::find($id);

        if (!$producto) {
            return response()->json([
                'success' => 0,
                'message' => 'Producto no encontrado',
            ], 404);
        }

        // ── Validación ─────────────────────────────────────────
        $validator = validator($request->all(), [
            'sku'              => 'nullable|string|max:100|unique:productos,sku,' . $id,
            'nombre'           => 'required|string|max:300',
            'descripcion'      => 'nullable|string',
            'imagen'           => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'tipo'             => 'required|in:bien,servicio,bien_servicio',
            'costo_unitario'   => 'required|numeric|min:0',
            'unidad_medida_id' => 'required|integer|exists:unidades_medida,id',
            'activo'           => 'nullable|boolean',
            'categorias'       => 'nullable|array',
            'categorias.*'     => 'integer|exists:categorias,id',
        ], [
            'nombre.required'           => 'El nombre es requerido',
            'tipo.required'             => 'El tipo es requerido',
            'tipo.in'                   => 'El tipo no es válido',
            'costo_unitario.required'   => 'El costo unitario es requerido',
            'costo_unitario.numeric'    => 'El costo unitario debe ser numérico',
            'unidad_medida_id.required' => 'La unidad de medida es requerida',
            'unidad_medida_id.exists'   => 'La unidad de medida no existe',
            'sku.unique'                => 'El SKU ya está registrado',
            'imagen.image'              => 'El archivo debe ser una imagen válida',
            'imagen.mimes'              => 'La imagen debe ser JPG o PNG',
            'imagen.max'                => 'La imagen debe pesar menos de 5MB',
            'categorias.*.exists'       => 'Una o más categorías no existen',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {

            // 1) Si viene nueva imagen, guardarla y borrar la anterior
            $imagenAnterior = $producto->imagen;
            $nombreImagen   = $producto->imagen;

            if ($request->hasFile('imagen')) {
                $foto         = $request->file('imagen');
                $nombreImagen = time() . '_' . Str::random(15) . '.' . $foto->getClientOriginalExtension();
                $foto->move(Storage::disk('archivos')->path(''), $nombreImagen);
            }

            // 2) Actualizar el producto
            $producto->sku              = $request->input('sku') ?: $producto->sku;
            $producto->nombre           = $request->input('nombre');
            $producto->descripcion      = $request->input('descripcion');
            $producto->imagen           = $nombreImagen;
            $producto->tipo             = $request->input('tipo');
            $producto->costo_unitario   = $request->input('costo_unitario');
            $producto->unidad_medida_id = $request->input('unidad_medida_id');

            if ($request->has('activo')) {
                $producto->activo = filter_var($request->input('activo'), FILTER_VALIDATE_BOOLEAN);
            }

            $producto->save();

            // 3) Sincronizar categorías
            $producto->categorias()->sync($request->input('categorias', []));

            DB::commit();

            // 4) Borrar imagen anterior solo si se reemplazó correctamente
            if ($request->hasFile('imagen') && $imagenAnterior && Storage::disk('archivos')->exists($imagenAnterior)) {
                Storage::disk('archivos')->delete($imagenAnterior);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Producto actualizado correctamente',
                'id'      => $producto->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Limpieza: si subimos imagen nueva pero falló, eliminarla
            if ($request->hasFile('imagen') && !empty($nombreImagen) && $nombreImagen !== $imagenAnterior
                && Storage::disk('archivos')->exists($nombreImagen)) {
                Storage::disk('archivos')->delete($nombreImagen);
            }

            return response()->json([
                'success' => 0,
                'message' => 'Error al actualizar el producto: ' . $e->getMessage(),
            ], 500);
        }
    }






}
