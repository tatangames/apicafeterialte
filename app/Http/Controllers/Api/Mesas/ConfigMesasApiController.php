<?php

namespace App\Http\Controllers\Api\Mesas;

use App\Http\Controllers\Controller;
use App\Models\AreaPreparacion;
use App\Models\Categoria;
use App\Models\GrupoModificador;
use App\Models\Modificador;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Terminal;
use App\Models\UnidadesMedida;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Configuración de mesas: terminales, áreas de preparación y modificadores. */
class ConfigMesasApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json(['success' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }

    // ── Terminales ────────────────────────────────────────────

    public function terminales()
    {
        return response()->json([
            'success'    => 1,
            'terminales' => Terminal::with('caja:id,nombre')->orderBy('orden')->orderBy('nombre')->get()->map(fn (Terminal $t) => [
                ...$t->only(['id', 'nombre', 'tipo', 'zona', 'capacidad', 'orden', 'caja_id', 'activo']),
                'caja'    => $t->caja?->nombre,
                'ocupada' => Orden::where('terminal_id', $t->id)->where('estado', 'abierta')->exists(),
            ]),
            'zonas'      => Terminal::whereNotNull('zona')->distinct()->orderBy('zona')->pluck('zona'),
        ]);
    }

    public function guardarTerminal(Request $request, ?int $id = null)
    {
        $terminal = $id ? Terminal::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre'    => 'required|string|max:60',
            'tipo'      => 'required|in:mesa,caja',
            'zona'      => 'nullable|string|max:60',
            'capacidad' => 'nullable|integer|min:0|max:99',
            'orden'     => 'nullable|integer|min:0|max:9999',
            'caja_id'   => 'nullable|required_if:tipo,caja|integer|exists:cajas,id',
            'activo'    => 'nullable|boolean',
        ], [
            'nombre.required'     => 'Ponle un nombre a la terminal',
            'caja_id.required_if' => 'Indica qué caja usa la terminal de mostrador',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $datos = $validator->validated();

        if ($terminal && ($datos['activo'] ?? true) === false && Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->exists()) {
            return response()->json(['success' => 0, 'message' => 'La terminal tiene una orden abierta: ciérrala antes de desactivarla'], 422);
        }

        $antes = $terminal?->toArray();
        // La posición se cambia arrastrando las filas: una terminal nueva va al final
        $terminal ? $terminal->update($datos) : $terminal = Terminal::create([
            ...$datos, 'activo' => $datos['activo'] ?? true, 'orden' => $datos['orden'] ?? (int) Terminal::max('orden') + 1,
        ]);
        AuditoriaService::registrar('mesas', $antes ? 'editar_terminal' : 'crear_terminal', $terminal, $antes, $terminal->toArray());

        return response()->json(['success' => 1, 'message' => "Terminal {$terminal->nombre} guardada"], $antes ? 200 : 201);
    }

    public function actualizarTerminal(Request $request, int $id)
    {
        return $this->guardarTerminal($request, $id);
    }

    // PUT /mesas/config/terminales/orden {ids: [...]} en el orden en que deben aparecer
    public function ordenarTerminales(Request $request)
    {
        $validator = validator($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:terminales,id',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        DB::transaction(function () use ($request) {
            foreach ($request->ids as $n => $id) {
                Terminal::whereKey($id)->update(['orden' => $n + 1]);
            }
        });
        AuditoriaService::registrar('mesas', 'ordenar_terminales', null, null, ['ids' => $request->ids]);

        return response()->json(['success' => 1, 'message' => 'Orden de las terminales guardado']);
    }

    // ── Áreas de preparación y sus categorías ─────────────────

    public function areas()
    {
        return response()->json([
            'success'    => 1,
            'areas'      => AreaPreparacion::orderBy('nombre')->get(['id', 'nombre', 'color', 'impresora_id', 'activo']),
            'impresoras' => \App\Models\Impresora::where('activo', true)->orderBy('alias')->get(['id', 'alias as nombre', 'uso']),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre', 'area_preparacion_id']),
        ]);
    }

    public function guardarArea(Request $request, ?int $id = null)
    {
        $area = $id ? AreaPreparacion::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('areas_preparacion', 'nombre')->ignore($area?->id)],
            'color'  => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'impresora_id' => 'nullable|integer|exists:impresoras,id',
            'activo' => 'nullable|boolean',
        ], ['nombre.unique' => 'Ya existe un área con ese nombre']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $area ? $area->update($validator->validated()) : AreaPreparacion::create($validator->validated());

        return response()->json(['success' => 1, 'message' => 'Área guardada'], $area ? 200 : 201);
    }

    public function actualizarArea(Request $request, int $id)
    {
        return $this->guardarArea($request, $id);
    }

    // PUT /mesas/config/categorias {asignaciones: [{categoria_id, area_id|null}]}
    public function asignarCategorias(Request $request)
    {
        $validator = validator($request->all(), [
            'asignaciones'                => 'required|array',
            'asignaciones.*.categoria_id' => 'required|integer|exists:categorias,id',
            // Sin área (null): se entrega directo, como el pan de vitrina, y no imprime comanda
            'asignaciones.*.area_id'      => 'present|nullable|integer|exists:areas_preparacion,id',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        DB::transaction(function () use ($request) {
            foreach ($request->asignaciones as $a) {
                Categoria::whereKey($a['categoria_id'])->update(['area_preparacion_id' => $a['area_id']]);
            }
        });
        AuditoriaService::registrar('mesas', 'areas_categorias', null, null, ['asignaciones' => $request->asignaciones]);

        return response()->json(['success' => 1, 'message' => 'Áreas de las categorías guardadas']);
    }

    // ── Modificadores ─────────────────────────────────────────

    public function grupos()
    {
        // Las opciones quitadas del grupo no se muestran; las apagadas sí (se pueden volver a encender)
        $grupos = GrupoModificador::with(['opciones' => fn ($q) => $q->where('eliminada', false), 'opciones.insumo:id,nombre',
            'opciones.unidad:id,abreviatura', 'productos:id,nombre'])->orderBy('nombre')->get();

        return response()->json([
            'success'   => 1,
            'grupos'    => $grupos->map(fn (GrupoModificador $g) => [
                ...$g->only(['id', 'nombre', 'obligatorio', 'multiple', 'activo']),
                // obligatorio del grupo: elegir al menos una; «una sola» (exclusiva) y activo son de cada opción
                'opciones'  => $g->opciones->map(fn ($o) => [
                    'id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio, 'activo' => $o->activo,
                    'maximo' => max(1, (int) $o->cantidad_maxima), 'obligatorio' => $o->obligatorio, 'exclusiva' => $o->exclusiva,
                    'producto_id' => $o->producto_id, 'insumo' => $o->insumo?->nombre,
                    'cantidad' => $o->cantidad !== null ? (float) $o->cantidad : null,
                    'unidad_medida_id' => $o->unidad_medida_id, 'unidad' => $o->unidad?->abreviatura,
                ]),
                'productos' => $g->productos->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre]),
            ]),
            'productos' => Producto::where('activo', true)->where('se_vende', true)->orderBy('nombre')->get(['id', 'nombre']),
            // Lo que puede gastar una opción: productos con inventario, en una unidad de su mismo tipo
            'insumos'   => Producto::with('unidadMedida:id,abreviatura,tipo')->where('activo', true)->where('controla_stock', true)
                ->orderBy('nombre')->get()->map(fn (Producto $p) => [
                    'id' => $p->id, 'nombre' => $p->nombre, 'unidad_medida_id' => $p->unidad_medida_id,
                    'unidad' => $p->unidadMedida?->abreviatura, 'tipo' => $p->unidadMedida?->tipo,
                ]),
            'unidades'  => UnidadesMedida::orderBy('tipo')->orderBy('factor_base')->get(['id', 'nombre', 'abreviatura', 'tipo']),
        ]);
    }

    /**
     * Crea o edita un grupo con sus opciones y los productos que lo usan.
     * Las opciones que ya no vienen se desactivan (no se borran: hay órdenes que las citan).
     */
    public function guardarGrupo(Request $request, ?int $id = null)
    {
        $grupo = $id ? GrupoModificador::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre'            => 'required|string|max:80',
            'obligatorio'       => 'nullable|boolean',
            'multiple'          => 'nullable|boolean',
            'activo'            => 'nullable|boolean',
            'opciones'          => 'required|array|min:1|max:40',
            'opciones.*.id'     => 'nullable|integer',
            'opciones.*.nombre' => 'required|string|max:80',
            'opciones.*.precio' => 'nullable|numeric|min:0|max:9999',
            'opciones.*.maximo' => 'nullable|integer|min:1|max:20',
            'opciones.*.obligatorio' => 'nullable|boolean',
            'opciones.*.exclusiva'   => 'nullable|boolean',
            'opciones.*.activo'      => 'nullable|boolean',
            'opciones.*.producto_id'      => 'nullable|integer|exists:productos,id',
            'opciones.*.cantidad'         => 'nullable|required_with:opciones.*.producto_id|numeric|gt:0|max:99999',
            'opciones.*.unidad_medida_id' => 'nullable|required_with:opciones.*.producto_id|integer|exists:unidades_medida,id',
            'productos'         => 'nullable|array',
            'productos.*'       => 'integer|exists:productos,id',
        ], [
            'nombre.required'          => 'Ponle un nombre al grupo (ej. Leche, Saborizante)',
            'opciones.required'        => 'Agrega al menos una opción',
            'opciones.*.nombre.required' => 'Cada opción necesita un nombre',
            'opciones.*.cantidad.required_with'         => 'Indica cuánto gasta cada opción con insumo',
            'opciones.*.cantidad.gt'                    => 'La cantidad del insumo debe ser mayor a 0',
            'opciones.*.unidad_medida_id.required_with' => 'Indica la unidad del insumo de cada opción',
            'opciones.*.maximo.max'                     => 'Una opción se puede pedir como máximo 20 veces',
        ]);
        // El insumo de una opción tiene que llevar inventario y su unidad debe convertirse a la del insumo
        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->input('opciones', []) as $o) {
                if (empty($o['producto_id']) || empty($o['unidad_medida_id'])) {
                    continue;
                }
                $insumo = Producto::with('unidadMedida')->find($o['producto_id']);
                $unidad = UnidadesMedida::find($o['unidad_medida_id']);
                if (!$insumo || !$unidad) {
                    continue;
                }
                $opcion = $o['nombre'] ?? 'opción';
                if (!$insumo->controla_stock) {
                    $v->errors()->add('opciones', "«{$opcion}»: {$insumo->nombre} no lleva inventario, no se puede descontar");
                } elseif ($insumo->unidadMedida && $unidad->tipo !== $insumo->unidadMedida->tipo) {
                    $v->errors()->add('opciones', "«{$opcion}»: {$insumo->nombre} se mide en {$insumo->unidadMedida->nombre}, no en {$unidad->nombre}");
                }
            }
        });
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $datos = $validator->validated();

        DB::transaction(function () use (&$grupo, $datos) {
            // «Una sola» y activo son de cada opción; el grupo obligatorio pide elegir al menos una
            $campos = ['nombre' => $datos['nombre'], 'obligatorio' => (bool) ($datos['obligatorio'] ?? false), 'multiple' => true, 'activo' => true];
            $grupo ? $grupo->update($campos) : $grupo = GrupoModificador::create($campos);

            $conservadas = [];
            foreach ($datos['opciones'] as $n => $o) {
                $opcion = !empty($o['id']) ? Modificador::where('grupo_id', $grupo->id)->find($o['id']) : null;
                $valores = [
                    'nombre'           => $o['nombre'],
                    'precio'           => round((float) ($o['precio'] ?? 0), 2),
                    // Si no viene (versión anterior de la app) se queda como estaba
                    'cantidad_maxima'  => isset($o['maximo']) ? (int) $o['maximo'] : ($opcion?->cantidad_maxima ?? 1),
                    'producto_id'      => $o['producto_id'] ?? null,
                    'cantidad'         => !empty($o['producto_id']) ? $o['cantidad'] : null,
                    'unidad_medida_id' => !empty($o['producto_id']) ? $o['unidad_medida_id'] : null,
                    'orden'            => $n,
                    // Si no vienen (versión anterior de la app) se quedan como estaban
                    'obligatorio'      => isset($o['obligatorio']) ? (bool) $o['obligatorio'] : ($opcion?->obligatorio ?? false),
                    'exclusiva'        => isset($o['exclusiva']) ? (bool) $o['exclusiva'] : ($opcion?->exclusiva ?? false),
                    'activo'           => isset($o['activo']) ? (bool) $o['activo'] : ($opcion?->activo ?? true),
                    'eliminada'        => false,
                ];
                $opcion ? $opcion->update($valores) : $opcion = Modificador::create([...$valores, 'grupo_id' => $grupo->id]);
                $conservadas[] = $opcion->id;
            }
            Modificador::where('grupo_id', $grupo->id)->whereNotIn('id', $conservadas)->update(['activo' => false, 'eliminada' => true]);

            $grupo->productos()->sync($datos['productos'] ?? []);
        });

        AuditoriaService::registrar('mesas', 'guardar_modificadores', $grupo, null, [
            'grupo' => $grupo->nombre, 'opciones' => collect($datos['opciones'])->pluck('nombre')->all(), 'productos' => count($datos['productos'] ?? []),
        ]);

        return response()->json(['success' => 1, 'message' => "Grupo «{$grupo->nombre}» guardado"], $id ? 200 : 201);
    }

    public function actualizarGrupo(Request $request, int $id)
    {
        return $this->guardarGrupo($request, $id);
    }
}
