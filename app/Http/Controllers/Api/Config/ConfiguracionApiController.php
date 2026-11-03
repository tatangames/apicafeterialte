<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Impresora;
use App\Models\Proveedor;
use App\Models\UnidadesMedida;
use App\Services\AuditoriaService;
use App\Services\ImpresionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class ConfiguracionApiController extends Controller
{

    public function tablaCategorias(){
        // Con su área: el producto muestra a qué comanda va
        $categorias = Categoria::with('area:id,nombre')->orderBy('id', 'ASC')->get();

        return response()->json($categorias);
    }


    public function registrarCategoria(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
        ]);

        try {
            Categoria::create([
                'nombre' => $request->nombre,
                'estado' => true,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear categoria: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear categoria',
            ], 500);
        }
    }


    public function actualizarCategoria(Request $request, $id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json([
                'success' => 2,
                'message' => 'Categoria no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'estado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            // Datos básicos
            $categoria->nombre = $request->nombre;
            $categoria->estado = $request->estado;
            $categoria->save();


            return response()->json([
                'success' => 1,
                'message' => 'Categoria actualizado correctamente'
            ]);

        } catch (\Exception $e) {

            Log::error('Error actualizar: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }




    // ================================ UNIDAD DE MEDIDA ==============================


    public function tablaUnidadMedida(){
        $unidadmedida = UnidadesMedida::orderBy('id', 'ASC')->get();

        return response()->json($unidadmedida);
    }

    // Datos de conversión opcionales: sin ellos la unidad es de tipo "unidad" con factor 1
    private function reglasConversionUnidad(): array
    {
        return [
            'abreviatura' => 'nullable|string|max:10',
            'tipo'        => 'nullable|in:unidad,masa,volumen',
            'factor_base' => 'nullable|numeric|gt:0',
        ];
    }

    public function registrarUnidadMedida(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
        ] + $this->reglasConversionUnidad());

        try {
            $unidad = UnidadesMedida::create([
                'nombre'      => $request->nombre,
                'abreviatura' => $request->abreviatura,
                'tipo'        => $request->input('tipo', 'unidad'),
                'factor_base' => $request->input('factor_base', 1),
                'estado'      => true,
            ]);

            AuditoriaService::registrar('catalogos', 'crear_unidad', $unidad, null, $unidad->toArray());

            return response()->json([
                'success' => 1,
                'message' => 'creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear unidad medida: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear unidad medida',
            ], 500);
        }
    }

    public function actualizarUnidadMedida(Request $request, $id)
    {
        $unidadmedida = UnidadesMedida::find($id);

        if (!$unidadmedida) {
            return response()->json([
                'success' => 2,
                'message' => 'Unidad medida no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'estado' => 'required|boolean',
        ] + $this->reglasConversionUnidad());

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $antes = $unidadmedida->toArray();

            // Datos básicos
            $unidadmedida->nombre = $request->nombre;
            $unidadmedida->estado = $request->estado;

            // La conversión solo cambia si se envía (la pantalla anterior solo manda nombre y estado)
            foreach (['abreviatura', 'tipo', 'factor_base'] as $campo) {
                if ($request->has($campo)) {
                    $unidadmedida->{$campo} = $request->input($campo);
                }
            }
            $unidadmedida->save();

            AuditoriaService::registrar('catalogos', 'actualizar_unidad', $unidadmedida, $antes, $unidadmedida->fresh()->toArray());


            return response()->json([
                'success' => 1,
                'message' => 'Unidad medida actualizado correctamente'
            ]);

        } catch (\Exception $e) {

            Log::error('Error actualizar: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }



    // ================================ IMPRESORA ==============================

    private function formatoImpresora(Impresora $i): array
    {
        return $i->toArray() + [
            'cajas' => Caja::where('impresora_id', $i->id)->pluck('nombre'),
            'areas' => AreaPreparacion::where('impresora_id', $i->id)->pluck('nombre'),
        ];
    }

    private function reglasImpresora(): array
    {
        return [
            'alias'            => 'required|string|max:200',
            'conexion'         => 'required|in:red,windows',
            'ip_impresora'     => 'nullable|required_if:conexion,red|ip',
            'puerto'           => 'nullable|integer|min:1|max:65535',
            'nombre_windows'   => 'nullable|required_if:conexion,windows|string|max:200',
            'ip_servidor'      => 'nullable|string|max:50',
            'tipo_impresion'   => 'nullable|in:escpos,star_raster',
            'ancho_caracteres' => 'required|in:32,42,48',
            'uso'              => 'required|in:ticket,comanda,ambos',
            'predeterminada'   => 'boolean',
            'cortar_papel'     => 'boolean',
            'cash_drawer'      => 'required|in:ninguno,pin2,pin5',
            'activo'           => 'boolean',
        ];
    }

    private function mensajesImpresora(): array
    {
        return [
            'alias.required'             => 'Ponle un nombre a la impresora',
            'ip_impresora.required_if'   => 'Indica la IP de la impresora de red',
            'ip_impresora.ip'            => 'La IP no es válida (ej. 192.168.1.50)',
            'nombre_windows.required_if' => 'Indica el nombre de la impresora tal como aparece en Windows',
            'ancho_caracteres.in'        => 'Ancho de papel no válido',
        ];
    }

    // Guarda los datos comunes de alta y edición
    private function guardarImpresora(Request $request, ?Impresora $impresora = null)
    {
        $validator = Validator::make($request->all(), $this->reglasImpresora(), $this->mensajesImpresora());

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $datos = [
            'tipo_impresion' => $request->input('tipo_impresion') ?: 'escpos',
            'puerto'         => $request->input('puerto') ?: 9100,
        ] + $validator->validated();

        $antes = $impresora?->toArray();

        DB::transaction(function () use (&$impresora, $datos) {
            if ($impresora) {
                $impresora->update($datos);
            } else {
                $impresora = Impresora::create($datos);
            }

            // Solo una predeterminada
            if ($impresora->predeterminada) {
                Impresora::whereKeyNot($impresora->id)->update(['predeterminada' => false]);
            }
        });

        AuditoriaService::registrar('impresoras', $antes ? 'actualizar' : 'crear', $impresora, $antes, $impresora->fresh()->toArray());

        return response()->json([
            'success' => 1,
            'message' => $antes ? 'Impresora actualizada correctamente.' : 'Impresora registrada correctamente.',
            'data'    => $this->formatoImpresora($impresora->fresh()),
        ], $antes ? 200 : 201);
    }

    // GET /admin/impresora/index
    public function indexImpresora()
    {
        $impresoras = Impresora::orderBy('alias')->get();
        $cajas = Caja::whereNotNull('impresora_id')->get(['nombre', 'impresora_id'])->groupBy('impresora_id');
        // Las comandas se reparten por área de preparación (Configuración › Mesas y cocina)
        $areas = AreaPreparacion::whereNotNull('impresora_id')->get(['nombre', 'impresora_id'])->groupBy('impresora_id');

        return response()->json([
            'data' => $impresoras->map(fn ($i) => $i->toArray() + [
                'cajas' => ($cajas[$i->id] ?? collect())->pluck('nombre'),
                'areas' => ($areas[$i->id] ?? collect())->pluck('nombre'),
            ]),
        ]);
    }

    // POST /admin/impresora/store
    public function nuevaImpresora(Request $request)
    {
        return $this->guardarImpresora($request);
    }

    // GET /admin/impresora/{impresora}
    public function showImpresora(Impresora $impresora)
    {
        return response()->json([
            'data' => $this->formatoImpresora($impresora),
        ]);
    }

    // POST /admin/impresora/{impresora}/update
    public function updateImpresora(Request $request, Impresora $impresora)
    {
        return $this->guardarImpresora($request, $impresora);
    }

    // DELETE /admin/impresora/{impresora}/destroy
    public function borrarImpresora(Impresora $impresora)
    {
        // Las cajas que la usaban pasan a la predeterminada
        Caja::where('impresora_id', $impresora->id)->update(['impresora_id' => null]);
        $impresora->delete();

        AuditoriaService::registrar('impresoras', 'eliminar', $impresora, $impresora->toArray(), null);

        return response()->json([
            'success' => 1,
            'message' => 'Impresora eliminada correctamente.',
        ]);
    }

    // POST /admin/impresora/{impresora}/test
    public function testPrintImpresoraReal(Request $request, Impresora $impresora)
    {
        $trabajo = ImpresionService::prueba($impresora, $request->user()?->id);
        $ok = $trabajo->estado === 'impreso';

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Impreso correctamente' : $trabajo->error,
            'trabajo' => $trabajo->only(['id', 'estado', 'error']),
        ], $ok ? 200 : 422);
    }
}
