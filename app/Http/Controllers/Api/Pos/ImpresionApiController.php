<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Impresora;
use App\Models\Pedido;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Services\AuditoriaService;
use App\Services\ImpresionService;
use Illuminate\Http\Request;

class ImpresionApiController extends Controller
{
    private function formatoTrabajo(TrabajoImpresion $t): array
    {
        return [
            'id'             => $t->id,
            'tipo'           => $t->tipo,
            'tipo_texto'     => TrabajoImpresion::TIPOS[$t->tipo] ?? $t->tipo,
            'impresora_id'   => $t->impresora_id,
            'impresora'      => $t->impresora?->alias,
            'documento_tipo' => $t->documento_tipo,
            'documento_id'   => $t->documento_id,
            'estado'         => $t->estado,
            'intentos'       => $t->intentos,
            'error'          => $t->error,
            'usuario'        => $t->usuario?->nombre,
            'fecha'          => $t->created_at->format('Y-m-d H:i'),
        ];
    }

    // Respuesta común: impreso = todos los trabajos salieron bien
    private function resultado(array $trabajos)
    {
        $errores = collect($trabajos)->where('estado', 'error');

        return response()->json([
            'success'  => 1,
            'impreso'  => $errores->isEmpty(),
            'message'  => $errores->isEmpty() ? 'Impreso' : $errores->pluck('error')->implode(' · '),
            'trabajos' => collect($trabajos)->map(fn ($t) => $this->formatoTrabajo($t->load('impresora'))),
        ]);
    }

    // POST /impresion/ventas/{venta}  {caja_id?, copia?, comandas?}
    public function venta(Request $request, Venta $venta)
    {
        $request->validate([
            'caja_id'  => 'nullable|integer|exists:cajas,id',
            'copia'    => 'nullable|boolean',
            'comandas' => 'nullable|boolean',
        ]);

        return $this->resultado(ImpresionService::imprimirVenta(
            $venta,
            $request->integer('caja_id') ?: null,
            $request->user()->id,
            $request->boolean('copia'),
            $request->boolean('comandas'),
        ));
    }

    // POST /impresion/pedidos/{pedido}/comandas
    public function comandasPedido(Request $request, Pedido $pedido)
    {
        return $this->resultado(ImpresionService::imprimirComandasPedido($pedido, $request->user()->id));
    }

    // POST /impresion/turnos/{turno}  {caja_id?}
    public function turno(Request $request, TurnoCaja $turno)
    {
        $request->validate(['caja_id' => 'nullable|integer|exists:cajas,id']);

        return $this->resultado([ImpresionService::imprimirCierre($turno, $request->integer('caja_id') ?: null, $request->user()->id)]);
    }

    // POST /impresion/cajon  {caja_id} — abrir la gaveta sin venta (queda en auditoría)
    public function cajon(Request $request)
    {
        $request->validate(['caja_id' => 'required|integer|exists:cajas,id', 'motivo' => 'required|string|max:200'], [
            'motivo.required' => 'Indica por qué se abre el cajón',
        ]);

        $trabajo = ImpresionService::abrirCajon($request->integer('caja_id'), $request->user()->id);

        AuditoriaService::registrar('caja', 'abrir_cajon', $trabajo, null, ['caja_id' => $request->caja_id], $request->motivo);

        return $this->resultado([$trabajo]);
    }

    // GET /impresion/trabajos?estado=error&page=
    public function trabajos(Request $request)
    {
        $request->validate(['estado' => 'nullable|in:pendiente,impreso,error']);

        $trabajos = TrabajoImpresion::with(['impresora', 'usuario:id,nombre'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'success'       => 1,
            'trabajos'      => collect($trabajos->items())->map(fn ($t) => $this->formatoTrabajo($t)),
            'con_error'     => TrabajoImpresion::where('estado', 'error')->where('created_at', '>=', now()->subDay())->count(),
            'pagina'        => $trabajos->currentPage(),
            'total_paginas' => $trabajos->lastPage(),
            'total'         => $trabajos->total(),
        ]);
    }

    // POST /impresion/trabajos/{trabajo}/reintentar  {impresora_id?}
    public function reintentar(Request $request, TrabajoImpresion $trabajo)
    {
        $request->validate(['impresora_id' => 'nullable|integer|exists:impresoras,id']);

        $otra = $request->filled('impresora_id') ? Impresora::find($request->impresora_id) : null;

        return $this->resultado([ImpresionService::reintentar($trabajo, $otra)]);
    }

    // DELETE /impresion/trabajos/{trabajo}: quita de la lista un error que ya no importa (no se imprime)
    public function eliminar(TrabajoImpresion $trabajo)
    {
        if ($trabajo->estado !== 'error') {
            return response()->json(['success' => false, 'message' => 'Solo se quitan las impresiones con error'], 422);
        }

        AuditoriaService::registrar('impresion', 'eliminar_error', $trabajo, [
            'tipo' => $trabajo->tipo, 'error' => $trabajo->error, 'impresora' => $trabajo->impresora?->alias,
        ]);
        $trabajo->delete();

        return response()->json(['success' => 1, 'message' => 'Error de impresión eliminado']);
    }

    // DELETE /impresion/trabajos: quita todas las impresiones con error
    public function eliminarErrores()
    {
        $total = TrabajoImpresion::where('estado', 'error')->count();
        if ($total > 0) {
            AuditoriaService::registrar('impresion', 'eliminar_errores', null, null, ['cantidad' => $total]);
            TrabajoImpresion::where('estado', 'error')->delete();
        }

        return response()->json(['success' => 1, 'message' => $total ? "{$total} error(es) de impresión eliminado(s)" : 'No había errores']);
    }
}
