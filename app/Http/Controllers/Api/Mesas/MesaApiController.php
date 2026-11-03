<?php

namespace App\Http\Controllers\Api\Mesas;

use App\Http\Controllers\Api\Pos\VentaApiController;
use App\Http\Controllers\Controller;
use App\Models\Comanda;
use App\Models\GrupoModificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenTicket;
use App\Models\Producto;
use App\Models\Terminal;
use App\Services\ImpresionService;
use App\Services\OrdenService;
use Illuminate\Http\Request;

class MesaApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function validar(Request $request, array $reglas, array $mensajes = [])
    {
        $validator = validator($request->all(), $reglas, $mensajes);
        return $validator->fails() ? [null, $this->errorValidacion($validator)] : [$validator->validated(), null];
    }

    public static function formatoItem(OrdenItem $i): array
    {
        return [
            'id'              => $i->id,
            'ticket_id'       => $i->ticket_id,
            'producto_id'     => $i->producto_id,
            'nombre'          => $i->nombre_producto,
            'descripcion'     => $i->descripcion(),
            'cantidad'        => (float) $i->cantidad,
            'precio_unitario' => (float) $i->precio_unitario,
            'subtotal'        => $i->subtotal(),
            'modificadores'   => $i->modificadores ?? [],
            'notas'           => $i->notas,
            'estado'          => $i->estado,
            'area'            => $i->area?->nombre,
            'comanda'         => $i->comanda?->numero,
            'pagado'          => (bool) $i->venta_id,
            'venta_id'        => $i->venta_id,
            'usuario'         => $i->usuario?->nombre,
            'hora'            => $i->created_at->format('H:i'),
        ];
    }

    public static function formatoComanda(Comanda $c): array
    {
        $c->loadMissing(['area', 'orden.terminal', 'usuario:id,nombre', 'items']);

        return [
            'id'           => $c->id,
            'numero'       => $c->numero,
            'orden_id'     => $c->orden_id,
            'terminal'     => $c->orden->terminal->nombre,
            'area'         => $c->area?->nombre,
            'color'        => $c->area?->color ?? '#6b7280',
            'estado'       => $c->estado,
            'notas'        => $c->notas,
            'usuario'      => $c->usuario?->nombre,
            'enviada_at'   => $c->enviada_at->format('Y-m-d H:i:s'),
            'iniciada_at'  => $c->iniciada_at?->format('Y-m-d H:i:s'),
            'terminada_at' => $c->terminada_at?->format('Y-m-d H:i:s'),
            'entregada_at' => $c->entregada_at?->format('Y-m-d H:i:s'),
            'items'        => $c->items->map(fn (OrdenItem $i) => [
                'id'            => $i->id,
                'nombre'        => $i->nombre_producto,
                'cantidad'      => (float) $i->cantidad,
                'modificadores' => collect($i->modificadores ?? [])->pluck('opcion')->all(),
                'notas'         => $i->notas,
                'cancelado'     => $i->estado === 'cancelado',
            ]),
        ];
    }

    public static function formatoOrden(Orden $o): array
    {
        $o->load(['terminal', 'cliente:id,nombre', 'usuario:id,nombre', 'tickets',
            'items' => fn ($q) => $q->with(['area:id,nombre', 'comanda:id,numero', 'usuario:id,nombre'])->orderBy('id'),
            'comandas' => fn ($q) => $q->orderByDesc('id')]);

        $vigentes = $o->items->where('estado', '!=', 'cancelado');

        return [
            'id'          => $o->id,
            'uuid'        => $o->uuid,
            'numero'      => $o->numero,
            'estado'      => $o->estado,
            'terminal'    => ['id' => $o->terminal->id, 'nombre' => $o->terminal->nombre, 'tipo' => $o->terminal->tipo],
            'comensales'  => $o->comensales,
            'cliente'     => $o->cliente ? ['id' => $o->cliente->id, 'nombre' => $o->cliente->nombre] : null,
            'usuario'     => $o->usuario?->nombre,
            'notas'       => $o->notas,
            'abierta_at'  => $o->abierta_at->format('Y-m-d H:i:s'),
            'tickets'     => $o->tickets->map(function (OrdenTicket $t) use ($o) {
                $items = $o->items->where('ticket_id', $t->id);
                $vigentes = $items->where('estado', '!=', 'cancelado');
                return [
                    'id'         => $t->id,
                    'nombre'     => $t->nombre,
                    'estado'     => $t->estado,
                    'posicion'   => $t->posicion,
                    'total'      => round($vigentes->sum(fn ($i) => $i->subtotal()), 2),
                    'por_cobrar' => round($vigentes->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                    'items'      => $items->map(fn ($i) => self::formatoItem($i))->values(),
                ];
            })->values(),
            'comandas'    => $o->comandas->map(fn ($c) => self::formatoComanda($c))->values(),
            'totales'     => [
                'total'      => round($vigentes->sum(fn ($i) => $i->subtotal()), 2),
                'cobrado'    => round($vigentes->whereNotNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                'por_cobrar' => round($vigentes->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                'por_enviar' => $vigentes->where('estado', 'pendiente')->count(),
                'listos'     => $vigentes->where('estado', 'listo')->count(),
            ],
        ];
    }

    // ── Mapa y catálogo ───────────────────────────────────────

    // GET /mesas/mapa
    public function mapa()
    {
        $terminales = Terminal::with(['ordenAbierta.tickets', 'ordenAbierta.usuario:id,nombre',
            'ordenAbierta.items' => fn ($q) => $q->where('estado', '!=', 'cancelado')])
            ->where('activo', true)->orderBy('orden')->orderBy('nombre')->get();

        return response()->json([
            'success'    => 1,
            'terminales' => $terminales->map(function (Terminal $t) {
                $o = $t->ordenAbierta;
                return [
                    'id'        => $t->id,
                    'nombre'    => $t->nombre,
                    'tipo'      => $t->tipo,
                    'zona'      => $t->zona,
                    'capacidad' => $t->capacidad,
                    'caja_id'   => $t->caja_id,
                    'orden'     => $o ? [
                        'id'         => $o->id,
                        'numero'     => $o->numero,
                        'abierta_at' => $o->abierta_at->format('Y-m-d H:i:s'),
                        'comensales' => $o->comensales,
                        'usuario'    => $o->usuario?->nombre,
                        'tickets'    => $o->tickets->where('estado', 'abierto')->pluck('nombre')->values(),
                        'total'      => round($o->items->sum(fn ($i) => $i->subtotal()), 2),
                        'por_cobrar' => round($o->items->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                        'por_enviar' => $o->items->where('estado', 'pendiente')->count(),
                        'listos'     => $o->items->where('estado', 'listo')->count(),
                    ] : null,
                ];
            }),
        ]);
    }

    // GET /mesas/catalogo: productos a la venta con sus grupos de modificadores
    public function catalogo()
    {
        $productos = Producto::with(['categorias:id,nombre', 'gruposModificadores:id', 'inventario:producto_id,stock_actual'])
            ->where('activo', true)->where('se_vende', true)->orderBy('orden_pos')->orderBy('nombre')->get();

        $grupos = GrupoModificador::with(['opciones' => fn ($q) => $q->where('activo', true)])->where('activo', true)->get();

        return response()->json([
            'success'    => 1,
            'productos'  => $productos->map(fn (Producto $p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'sku'              => $p->sku,
                'codigo_barras'    => $p->codigo_barras,
                'precio'           => (float) $p->precio_venta,
                'imagen'           => $p->imagen,
                'color'            => $p->color_pos,
                'permite_fraccion' => $p->permite_fraccion,
                'categorias'       => $p->categorias->pluck('id'),
                'grupos'           => $p->gruposModificadores->pluck('id'),
                'stock'            => $p->controla_stock ? (float) ($p->inventario?->stock_actual ?? 0) : null,
            ]),
            'categorias' => $productos->flatMap->categorias->unique('id')->sortBy('nombre')->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),
            'grupos'     => $grupos->map(fn (GrupoModificador $g) => [
                'id'          => $g->id,
                'nombre'      => $g->nombre,
                'obligatorio' => $g->obligatorio,
                'multiple'    => $g->multiple,
                'opciones'    => $g->opciones->map(fn ($o) => ['id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio]),
            ]),
        ]);
    }

    // ── Orden de una terminal ─────────────────────────────────

    // GET /mesas/terminales/{terminal}: la orden abierta (o null si está libre)
    public function terminal(Terminal $terminal)
    {
        $orden = Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->first();

        return response()->json([
            'success'  => 1,
            'terminal' => ['id' => $terminal->id, 'nombre' => $terminal->nombre, 'tipo' => $terminal->tipo, 'zona' => $terminal->zona, 'capacidad' => $terminal->capacidad],
            'orden'    => $orden ? self::formatoOrden($orden) : null,
        ]);
    }

    // POST /mesas/terminales/{terminal}/abrir {comensales?, cliente_id?, notas?}
    public function abrir(Request $request, Terminal $terminal)
    {
        [$datos, $error] = $this->validar($request, [
            'uuid'       => 'nullable|uuid',
            'comensales' => 'nullable|integer|min:1|max:99',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'notas'      => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        $orden = OrdenService::abrir($terminal, $request->user(), $datos);

        return response()->json(['success' => 1, 'message' => "{$terminal->nombre} abierta", 'orden' => self::formatoOrden($orden)]);
    }

    // PUT /mesas/ordenes/{orden} {comensales?, cliente_id?, notas?}
    public function actualizar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'comensales' => 'nullable|integer|min:1|max:99',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'notas'      => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        abort_if($orden->estado !== 'abierta', 422, 'La orden ya está cerrada');
        $orden->update($datos);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden)]);
    }

    // POST /mesas/ordenes/{orden}/items {ticket_id?, items: [{producto_id, cantidad, modificadores, notas}]}
    public function agregar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'ticket_id'               => 'nullable|integer',
            'items'                   => 'required|array|min:1|max:50',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0|max:9999',
            'items.*.modificadores'   => 'nullable|array|max:20',
            'items.*.modificadores.*' => 'integer',
            'items.*.notas'           => 'nullable|string|max:200',
        ], ['items.required' => 'Agrega al menos un producto']);
        if ($error) return $error;

        OrdenService::agregarItems($orden, $datos['ticket_id'] ?? null, $datos['items'], $request->user());

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // PUT /mesas/items/{item} {cantidad?, notas?}
    public function actualizarItem(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, [
            'cantidad' => 'nullable|numeric|gt:0|max:9999',
            'notas'    => 'nullable|string|max:200',
        ]);
        if ($error) return $error;

        OrdenService::actualizarItem($item, $request->only(array_keys($datos)));

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($item->orden->fresh())]);
    }

    // POST /mesas/items/{item}/quitar {motivo?}
    public function quitarItem(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, [
            'motivo'           => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        OrdenService::quitarItem($item, $request->user(), $datos['motivo'] ?? null);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($item->orden->fresh())]);
    }

    // POST /mesas/ordenes/{orden}/enviar {notas?}: comandas a cocina y barra
    public function enviar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['notas' => 'nullable|string|max:300']);
        if ($error) return $error;

        $comandas = OrdenService::enviar($orden, $request->user(), $datos['notas'] ?? null);

        // Imprimir nunca frena el envío: si falla, la comanda ya está en la pantalla de cocina
        $trabajos = $comandas->flatMap(fn ($c) => ImpresionService::imprimirComanda($c, $request->user()->id));
        $errores = $trabajos->where('estado', 'error');

        return response()->json([
            'success'   => 1,
            'message'   => $comandas->isEmpty()
                ? 'Productos entregados (no pasan por cocina)'
                : 'Comanda #' . $comandas->first()->numero . ' enviada a ' . $comandas->map(fn ($c) => $c->area?->nombre)->filter()->join(' y '),
            'impresion' => [
                'impresas' => $trabajos->where('estado', 'impreso')->count(),
                'errores'  => $errores->pluck('error')->values(),
                'sin_impresora' => $comandas->isNotEmpty() && $trabajos->isEmpty(),
            ],
            // Para imprimir desde el navegador (un ticket por área) si la térmica no está o falla
            'comandas'  => $comandas->map(fn ($c) => self::formatoComanda($c->fresh()))->values(),
            'orden'     => self::formatoOrden($orden->fresh()),
        ]);
    }

    // POST /mesas/ordenes/{orden}/entregar {item_ids}
    public function entregar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['item_ids' => 'required|array|min:1', 'item_ids.*' => 'integer']);
        if ($error) return $error;

        OrdenService::entregarItems($orden, $datos['item_ids']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // ── Cuentas separadas ─────────────────────────────────────

    // POST /mesas/ordenes/{orden}/tickets {nombre?}
    public function crearTicket(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['nombre' => 'nullable|string|max:60']);
        if ($error) return $error;

        $ticket = OrdenService::crearTicket($orden, $datos['nombre'] ?? null);

        return response()->json(['success' => 1, 'ticket_id' => $ticket->id, 'orden' => self::formatoOrden($orden->fresh())], 201);
    }

    // PUT /mesas/tickets/{ticket} {nombre}
    public function renombrarTicket(Request $request, OrdenTicket $ticket)
    {
        [$datos, $error] = $this->validar($request, ['nombre' => 'required|string|max:60'], ['nombre.required' => 'Ponle un nombre a la cuenta']);
        if ($error) return $error;

        OrdenService::renombrarTicket($ticket, $datos['nombre']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($ticket->orden->fresh())]);
    }

    // DELETE /mesas/tickets/{ticket}
    public function eliminarTicket(OrdenTicket $ticket)
    {
        $orden = $ticket->orden;
        OrdenService::eliminarTicket($ticket);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // POST /mesas/ordenes/{orden}/mover {item_ids, ticket_id}
    public function mover(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'ticket_id'  => 'required|integer',
        ]);
        if ($error) return $error;

        OrdenService::moverItems($orden, $datos['item_ids'], $datos['ticket_id']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // POST /mesas/items/{item}/dividir {cantidad, ticket_id}
    public function dividir(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, ['cantidad' => 'required|numeric|gt:0', 'ticket_id' => 'required|integer']);
        if ($error) return $error;

        OrdenService::dividirItem($item, (float) $datos['cantidad'], $datos['ticket_id']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($item->orden->fresh())]);
    }

    // ── Cobro y cierre ────────────────────────────────────────

    // POST /mesas/ordenes/{orden}/precuenta {ticket_id?, caja_id?} — en la térmica (la tablet no imprime desde el navegador)
    public function precuenta(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'ticket_id' => 'nullable|integer',
            'caja_id'   => 'nullable|integer|exists:cajas,id',
        ]);
        if ($error) return $error;

        $trabajo = ImpresionService::imprimirPrecuenta($orden, $datos['ticket_id'] ?? null, $datos['caja_id'] ?? null, $request->user()->id);

        return response()->json([
            'success' => 1,
            'impreso' => $trabajo->estado === 'impreso',
            'message' => $trabajo->estado === 'impreso' ? 'Precuenta impresa' : ($trabajo->error ?? 'No se pudo imprimir'),
        ]);
    }

    // POST /mesas/ordenes/{orden}/cobrar
    public function cobrar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'uuid'                   => 'required|uuid',
            'caja_id'                => 'required|integer|exists:cajas,id',
            'ticket_id'              => 'nullable|integer',
            'item_ids'               => 'nullable|array',
            'item_ids.*'             => 'integer',
            'cliente_id'             => 'nullable|integer',
            'descuento_global'       => 'nullable|array',
            'descuento_global.tipo'  => 'required_with:descuento_global|in:porcentaje,monto',
            'descuento_global.valor' => 'required_with:descuento_global|numeric|min:0',
            'pagos'                  => 'present|array|max:10',
            'pagos.*.metodo_pago_id' => 'required|integer',
            'pagos.*.monto'          => 'required|numeric|min:0|max:999999',
            'pagos.*.referencia'     => 'nullable|string|max:100',
        ], ['pagos.present' => 'Indica la forma de pago']);
        if ($error) return $error;

        $venta = OrdenService::cobrar($orden, $datos, $request->user());
        $orden = $orden->fresh();

        return response()->json([
            'success' => 1,
            'message' => "Venta {$venta->numero} registrada" . ($orden->estado === 'cerrada' ? " · {$orden->terminal->nombre} queda libre" : ''),
            'venta'   => VentaApiController::formato($venta),
            'orden'   => self::formatoOrden($orden),
        ], $venta->wasRecentlyCreated ? 201 : 200);
    }

    // POST /mesas/ordenes/{orden}/cerrar {motivo?}: liberar la terminal
    public function cerrar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'motivo'           => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        $orden = OrdenService::cerrar($orden, $request->user(), $datos['motivo'] ?? null);

        return response()->json(['success' => 1, 'message' => "{$orden->terminal->nombre} liberada"]);
    }

    // POST /mesas/ordenes/{orden}/trasladar {terminal_id}
    public function trasladar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['terminal_id' => 'required|integer|exists:terminales,id']);
        if ($error) return $error;

        $orden = OrdenService::trasladar($orden, Terminal::find($datos['terminal_id']), $request->user());

        return response()->json(['success' => 1, 'message' => "Orden pasada a {$orden->terminal->nombre}", 'orden' => self::formatoOrden($orden)]);
    }

    // POST /mesas/comandas/{comanda}/entregar
    public function entregarComanda(Comanda $comanda)
    {
        $comanda = OrdenService::entregarComanda($comanda);

        return response()->json(['success' => 1, 'comanda' => self::formatoComanda($comanda->fresh())]);
    }

    // POST /mesas/comandas/{comanda}/imprimir — reimpresión (sale marcada como COPIA)
    public function imprimirComanda(Request $request, Comanda $comanda)
    {
        $trabajos = collect(ImpresionService::imprimirComanda($comanda, $request->user()->id, true));
        $errores = $trabajos->where('estado', 'error');

        return response()->json([
            'success' => 1,
            'impreso' => $trabajos->isNotEmpty() && $errores->isEmpty(),
            'comanda' => self::formatoComanda($comanda),
            'message' => $trabajos->isEmpty()
                ? 'No hay impresora de comandas para estos productos: imprime desde el navegador'
                : ($errores->isEmpty() ? 'Comanda impresa' : $errores->pluck('error')->implode(' · ')),
        ]);
    }
}
