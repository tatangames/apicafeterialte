<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Comanda;
use App\Models\GrupoModificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenTicket;
use App\Models\Producto;
use App\Models\Terminal;
use App\Models\Venta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Órdenes de mesa: se abren en una terminal, se les agregan productos (con
 * modificadores) en una o varias cuentas, se envían a cocina o barra como
 * comandas y se cobran por cuenta o por productos elegidos. El cobro usa
 * VentaService: inventario, caja y ticket funcionan igual que en mostrador.
 */
class OrdenService
{
    // ── Orden ─────────────────────────────────────────────────

    /** Orden abierta de la terminal; si no hay, la abre con su primera cuenta. */
    public static function abrir(Terminal $terminal, Administrador $usuario, array $datos = []): Orden
    {
        if (!$terminal->activo) {
            throw new NegocioException("{$terminal->nombre} está desactivada");
        }
        if ($terminal->tipo === 'caja') {
            throw new NegocioException("{$terminal->nombre} es la caja de mostrador: vende desde el punto de venta rápido");
        }

        if ($abierta = Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->first()) {
            return $abierta;
        }

        try {
            return DB::transaction(function () use ($terminal, $usuario, $datos) {
                $orden = Orden::create([
                    'uuid'        => $datos['uuid'] ?? (string) Str::uuid(),
                    'numero'      => CorrelativoService::siguiente('orden', 'O'),
                    'terminal_id' => $terminal->id,
                    'estado'      => 'abierta',
                    'comensales'  => $datos['comensales'] ?? null,
                    'cliente_id'  => $datos['cliente_id'] ?? null,
                    'usuario_id'  => $usuario->id,
                    'notas'       => $datos['notas'] ?? null,
                    'abierta_at'  => now(),
                ]);
                OrdenTicket::create(['orden_id' => $orden->id, 'nombre' => 'Ticket 1', 'posicion' => 1]);

                return $orden;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otro dispositivo la abrió al mismo tiempo: se usa esa
            return Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->firstOrFail();
        }
    }

    private static function bloquear(Orden $orden): Orden
    {
        $orden = Orden::whereKey($orden->id)->lockForUpdate()->first();
        if ($orden->estado !== 'abierta') {
            throw new NegocioException('La orden ya está cerrada: vuelve al mapa de mesas');
        }
        return $orden;
    }

    private static function ticketDe(Orden $orden, ?int $ticketId): OrdenTicket
    {
        $ticket = $ticketId
            ? OrdenTicket::where('orden_id', $orden->id)->whereKey($ticketId)->first()
            : OrdenTicket::where('orden_id', $orden->id)->where('estado', 'abierto')->orderBy('posicion')->first();

        if (!$ticket) {
            $ticket = self::nuevoTicket($orden, null);
        }
        if ($ticket->estado !== 'abierto') {
            throw new NegocioException("{$ticket->nombre} ya está pagado: agrega los productos en otra cuenta");
        }
        return $ticket;
    }

    // ── Productos de la orden ─────────────────────────────────

    /**
     * Valida los modificadores elegidos contra los grupos del producto y
     * devuelve [modificadores elegidos, precio extra].
     */
    public static function resolverModificadores(Producto $producto, array $ids): array
    {
        $grupos = $producto->gruposModificadores()->where('activo', true)
            ->with(['opciones' => fn ($q) => $q->where('activo', true)])->get();

        $elegidos = [];
        $extra = 0.0;
        $usados = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $grupo = $grupos->first(fn (GrupoModificador $g) => $g->opciones->contains('id', $id));
            if (!$grupo) {
                throw new NegocioException("Uno de los extras no corresponde a {$producto->nombre}");
            }
            if (!$grupo->multiple && isset($usados[$grupo->id])) {
                throw new NegocioException("En «{$grupo->nombre}» solo se elige una opción");
            }
            $usados[$grupo->id] = true;
            $opcion = $grupo->opciones->firstWhere('id', $id);
            $elegidos[] = ['id' => $opcion->id, 'grupo' => $grupo->nombre, 'opcion' => $opcion->nombre, 'precio' => (float) $opcion->precio];
            $extra += (float) $opcion->precio;
        }

        foreach ($grupos->where('obligatorio', true) as $grupo) {
            if (!isset($usados[$grupo->id])) {
                throw new NegocioException("Elige «{$grupo->nombre}» para {$producto->nombre}");
            }
        }

        return [$elegidos, round($extra, 2)];
    }

    /**
     * Agrega productos a una cuenta. Quedan "pendientes" hasta enviarlos.
     * @param array $items [['producto_id', 'cantidad', 'modificadores' => [ids], 'notas'], ...]
     */
    public static function agregarItems(Orden $orden, ?int $ticketId, array $items, Administrador $usuario): Collection
    {
        $productos = Producto::with(['categorias.area'])->whereIn('id', collect($items)->pluck('producto_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($orden, $ticketId, $items, $usuario, $productos) {
            $orden = self::bloquear($orden);
            $ticket = self::ticketDe($orden, $ticketId);

            return collect($items)->map(function ($i) use ($orden, $ticket, $usuario, $productos) {
                $producto = $productos->get($i['producto_id']);
                if (!$producto || !$producto->activo || !$producto->se_vende) {
                    throw new NegocioException('Uno de los productos no está disponible para la venta');
                }
                $cantidad = round((float) $i['cantidad'], 4);
                if ($cantidad <= 0) {
                    throw new NegocioException("La cantidad de {$producto->nombre} debe ser mayor a 0");
                }
                if (!$producto->permite_fraccion && floor($cantidad) != $cantidad) {
                    throw new NegocioException("{$producto->nombre} solo se vende por unidades enteras");
                }

                [$modificadores, $extra] = self::resolverModificadores($producto, $i['modificadores'] ?? []);

                // El área de preparación sale de la categoría del producto
                $area = $producto->categorias->map(fn ($c) => $c->area)->first(fn ($a) => $a && $a->activo);

                return OrdenItem::create([
                    'orden_id'        => $orden->id,
                    'ticket_id'       => $ticket->id,
                    'producto_id'     => $producto->id,
                    'nombre_producto' => $producto->nombre,
                    'cantidad'        => $cantidad,
                    'precio_base'     => (float) $producto->precio_venta,
                    'modificadores'   => $modificadores ?: null,
                    'precio_unitario' => round((float) $producto->precio_venta + $extra, 2),
                    'notas'           => $i['notas'] ?? null,
                    'estado'          => 'pendiente',
                    'area_id'         => $area?->id,
                    'usuario_id'      => $usuario->id,
                ]);
            });
        });
    }

    // Cambia cantidad o notas de un producto que todavía no se envía
    public static function actualizarItem(OrdenItem $item, array $datos): OrdenItem
    {
        return DB::transaction(function () use ($item, $datos) {
            self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            if ($item->estado !== 'pendiente') {
                throw new NegocioException('El producto ya se envió a preparar: para cambiarlo, cancélalo y agrégalo de nuevo');
            }
            if (isset($datos['cantidad'])) {
                $cantidad = round((float) $datos['cantidad'], 4);
                if ($cantidad <= 0) {
                    throw new NegocioException('La cantidad debe ser mayor a 0');
                }
                if (!$item->producto->permite_fraccion && floor($cantidad) != $cantidad) {
                    throw new NegocioException("{$item->nombre_producto} solo se vende por unidades enteras");
                }
                $item->cantidad = $cantidad;
            }
            if (array_key_exists('notas', $datos)) {
                $item->notas = $datos['notas'];
            }
            $item->save();

            return $item;
        });
    }

    /**
     * Quita un producto. Si no se ha enviado, se borra. Si ya se envió a
     * preparar, se cancela con motivo y autorización (queda en auditoría).
     */
    public static function quitarItem(OrdenItem $item, Administrador $usuario, ?string $motivo): void
    {
        if ($item->venta_id) {
            throw new NegocioException('El producto ya se cobró: anula la venta o registra una devolución');
        }

        if ($item->estado === 'pendiente') {
            DB::transaction(function () use ($item) {
                self::bloquear($item->orden);
                OrdenItem::whereKey($item->id)->where('estado', 'pendiente')->delete();
            });
            return;
        }

        if ($item->estado === 'cancelado') {
            throw new NegocioException('El producto ya está cancelado');
        }
        if (!$motivo) {
            throw new NegocioException('Indica por qué se cancela un producto que ya se envió a preparar');
        }
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Cancelar un producto ya enviado solo lo puede hacer el administrador');

        DB::transaction(function () use ($item, $usuario, $motivo, $autorizador) {
            self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            $item->update(['estado' => 'cancelado', 'cancelado_por' => $usuario->id, 'motivo_cancelacion' => $motivo]);

            // Si la comanda se quedó sin nada que preparar, también se cancela
            if ($item->comanda_id && !OrdenItem::where('comanda_id', $item->comanda_id)->where('estado', '!=', 'cancelado')->exists()) {
                Comanda::whereKey($item->comanda_id)->update(['estado' => 'cancelada']);
            }

            AuditoriaService::registrar('mesas', 'cancelar_item', $item, ['estado' => 'enviado'], [
                'producto'       => $item->descripcion(),
                'cantidad'       => (float) $item->cantidad,
                'autorizado_por' => $autorizador->nombre,
            ], $motivo);
        });
    }

    // ── Envío a cocina y barra ────────────────────────────────

    /**
     * Envía lo pendiente: una comanda por área con el mismo número. Todo sale en
     * comanda: lo que no tiene área va en una comanda general.
     * @return Collection<Comanda>
     */
    public static function enviar(Orden $orden, Administrador $usuario, ?string $notas = null): Collection
    {
        return DB::transaction(function () use ($orden, $usuario, $notas) {
            $orden = self::bloquear($orden);
            $pendientes = OrdenItem::where('orden_id', $orden->id)->where('estado', 'pendiente')->lockForUpdate()->get();

            if ($pendientes->isEmpty()) {
                throw new NegocioException('No hay productos nuevos para enviar');
            }

            $numero = null;
            $comandas = collect();
            foreach ($pendientes->groupBy('area_id') as $areaId => $items) {
                $numero ??= CorrelativoService::siguiente('comanda');
                $comanda = Comanda::create([
                    'numero'     => $numero,
                    'orden_id'   => $orden->id,
                    'area_id'    => $areaId ? (int) $areaId : null,
                    'estado'     => 'pendiente',
                    'notas'      => $notas,
                    'usuario_id' => $usuario->id,
                    'enviada_at' => now(),
                ]);
                OrdenItem::whereIn('id', $items->pluck('id'))->update(['estado' => 'enviado', 'comanda_id' => $comanda->id]);
                $comandas->push($comanda);
            }

            return $comandas;
        });
    }

    /**
     * Marca entregada una comanda desde la mesa. La cocina y la barra trabajan con
     * su ticket impreso, así que no hay pasos intermedios (preparando, listo).
     */
    public static function entregarComanda(Comanda $comanda): Comanda
    {
        return DB::transaction(function () use ($comanda) {
            $comanda = Comanda::whereKey($comanda->id)->lockForUpdate()->first();
            if (in_array($comanda->estado, ['entregada', 'cancelada'], true)) {
                throw new NegocioException('La comanda ya está entregada o cancelada');
            }

            $comanda->update(['estado' => 'entregada', 'entregada_at' => now(), 'iniciada_at' => $comanda->iniciada_at ?? now()]);
            OrdenItem::where('comanda_id', $comanda->id)->where('estado', '!=', 'cancelado')->update(['estado' => 'entregado']);

            return $comanda;
        });
    }

    // Marca entregados productos sueltos desde la mesa (ya están listos o no pasan por cocina)
    public static function entregarItems(Orden $orden, array $itemIds): void
    {
        DB::transaction(function () use ($orden, $itemIds) {
            $orden = self::bloquear($orden);
            OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)
                ->whereIn('estado', ['enviado', 'en_preparacion', 'listo'])->update(['estado' => 'entregado']);

            // Comandas cuyos productos ya se entregaron todos
            $comandas = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)->whereNotNull('comanda_id')->pluck('comanda_id')->unique();
            foreach ($comandas as $id) {
                $quedan = OrdenItem::where('comanda_id', $id)->whereNotIn('estado', ['entregado', 'cancelado'])->exists();
                if (!$quedan) {
                    Comanda::whereKey($id)->whereNotIn('estado', ['entregada', 'cancelada'])->update(['estado' => 'entregada', 'entregada_at' => now()]);
                }
            }
        });
    }

    // ── Cuentas separadas ─────────────────────────────────────

    public static function nuevoTicket(Orden $orden, ?string $nombre): OrdenTicket
    {
        $posicion = (int) OrdenTicket::where('orden_id', $orden->id)->max('posicion') + 1;

        return OrdenTicket::create([
            'orden_id' => $orden->id,
            'nombre'   => $nombre ?: "Ticket {$posicion}",
            'posicion' => $posicion,
        ]);
    }

    public static function crearTicket(Orden $orden, ?string $nombre): OrdenTicket
    {
        return DB::transaction(fn () => self::nuevoTicket(self::bloquear($orden), $nombre));
    }

    public static function renombrarTicket(OrdenTicket $ticket, string $nombre): OrdenTicket
    {
        $ticket->update(['nombre' => $nombre]);
        return $ticket;
    }

    // Pasa productos (sin cobrar) a otra cuenta de la misma orden
    public static function moverItems(Orden $orden, array $itemIds, int $ticketId): void
    {
        DB::transaction(function () use ($orden, $itemIds, $ticketId) {
            $orden = self::bloquear($orden);
            $destino = self::ticketDe($orden, $ticketId);

            $movibles = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)
                ->whereNull('venta_id')->where('estado', '!=', 'cancelado')->pluck('id');
            if ($movibles->count() !== count(array_unique($itemIds))) {
                throw new NegocioException('Solo se mueven productos de esta orden que no se han cobrado');
            }
            OrdenItem::whereIn('id', $movibles)->update(['ticket_id' => $destino->id]);
        });
    }

    // Separa una línea: pasa parte de la cantidad a otra cuenta (2 frappés → 1 y 1)
    public static function dividirItem(OrdenItem $item, float $cantidad, int $ticketId): OrdenItem
    {
        return DB::transaction(function () use ($item, $cantidad, $ticketId) {
            $orden = self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            $destino = self::ticketDe($orden, $ticketId);

            if ($item->venta_id || $item->estado === 'cancelado') {
                throw new NegocioException('Ese producto ya se cobró o se canceló');
            }
            if ($cantidad <= 0 || $cantidad >= (float) $item->cantidad) {
                throw new NegocioException('La cantidad a separar debe ser menor a la de la línea');
            }

            $item->update(['cantidad' => round((float) $item->cantidad - $cantidad, 4)]);
            $nuevo = $item->replicate();
            $nuevo->cantidad = $cantidad;
            $nuevo->ticket_id = $destino->id;
            $nuevo->save();

            return $nuevo;
        });
    }

    public static function eliminarTicket(OrdenTicket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            self::bloquear($ticket->orden);
            if (OrdenItem::where('ticket_id', $ticket->id)->where('estado', '!=', 'cancelado')->exists()) {
                throw new NegocioException('La cuenta tiene productos: muévelos a otra antes de quitarla');
            }
            if (OrdenTicket::where('orden_id', $ticket->orden_id)->count() <= 1) {
                throw new NegocioException('La orden debe tener al menos una cuenta');
            }
            OrdenItem::where('ticket_id', $ticket->id)->delete(); // solo quedan cancelados sin cobrar
            $ticket->delete();
        });
    }

    // ── Cobro ─────────────────────────────────────────────────

    /**
     * Cobra una cuenta completa o los productos elegidos. La venta se
     * registra con VentaService (stock, caja, cliente, crédito, descuentos)
     * y los productos quedan ligados a ella. Si ya no queda nada por cobrar,
     * la orden se cierra y la mesa queda libre.
     *
     * $datos: uuid, caja_id, ticket_id?, item_ids?, pagos, cliente_id?, descuento_global?, observaciones?
     */
    public static function cobrar(Orden $orden, array $datos, Administrador $usuario): Venta
    {
        if ($existente = Venta::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        return DB::transaction(function () use ($orden, $datos, $usuario) {
            $orden = self::bloquear($orden);

            $items = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')
                ->when(!empty($datos['item_ids']), fn ($q) => $q->whereIn('id', $datos['item_ids']))
                ->when(empty($datos['item_ids']) && !empty($datos['ticket_id']), fn ($q) => $q->where('ticket_id', $datos['ticket_id']))
                ->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new NegocioException('No hay productos por cobrar en esa cuenta');
            }
            if (!empty($datos['item_ids']) && $items->count() !== count(array_unique($datos['item_ids']))) {
                throw new NegocioException('Alguno de los productos ya se cobró o se canceló');
            }

            $venta = VentaService::registrar([
                'uuid'             => $datos['uuid'],
                'caja_id'          => $datos['caja_id'],
                'orden_id'         => $orden->id,
                'cliente_id'       => $datos['cliente_id'] ?? $orden->cliente_id,
                'items'            => $items->map(fn (OrdenItem $i) => [
                    'producto_id'  => $i->producto_id,
                    'cantidad'     => (float) $i->cantidad,
                    'precio_orden' => (float) $i->precio_unitario,
                    'nombre_orden' => $i->descripcion(),
                ])->all(),
                'descuento_global' => $datos['descuento_global'] ?? null,
                'pagos'            => $datos['pagos'],
                'observaciones'    => $datos['observaciones'] ?? "Orden {$orden->numero} · {$orden->terminal->nombre}",
            ], $usuario);

            OrdenItem::whereIn('id', $items->pluck('id'))->update(['venta_id' => $venta->id]);

            // Cuentas sin nada pendiente quedan pagadas
            foreach (OrdenTicket::where('orden_id', $orden->id)->where('estado', 'abierto')->get() as $ticket) {
                $pendiente = OrdenItem::where('ticket_id', $ticket->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->exists();
                $tieneItems = OrdenItem::where('ticket_id', $ticket->id)->where('estado', '!=', 'cancelado')->exists();
                if (!$pendiente && $tieneItems) {
                    $ticket->update(['estado' => 'pagado']);
                }
            }

            $quedaPorCobrar = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->exists();
            if (!$quedaPorCobrar) {
                $orden->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
            }

            return $venta;
        });
    }

    /**
     * Libera la mesa sin cobrar ("limpiar terminal"). Solo si no queda nada
     * por cobrar; lo pendiente se cancela con motivo y autorización.
     */
    public static function cerrar(Orden $orden, Administrador $usuario, ?string $motivo): Orden
    {
        $porCobrar = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->get();

        $autorizador = null;
        if ($porCobrar->isNotEmpty()) {
            if (!$motivo) {
                throw new NegocioException('La mesa tiene productos sin cobrar: indica por qué se cancelan');
            }
            $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Cancelar productos sin cobrar solo lo puede hacer el administrador');
        }

        return DB::transaction(function () use ($orden, $usuario, $motivo, $porCobrar, $autorizador) {
            $orden = self::bloquear($orden);

            if ($porCobrar->isNotEmpty()) {
                OrdenItem::whereIn('id', $porCobrar->pluck('id'))->update([
                    'estado' => 'cancelado', 'cancelado_por' => $usuario->id, 'motivo_cancelacion' => $motivo,
                ]);
                Comanda::where('orden_id', $orden->id)->whereIn('estado', ['pendiente', 'en_preparacion'])
                    ->whereDoesntHave('items', fn ($q) => $q->where('estado', '!=', 'cancelado'))
                    ->update(['estado' => 'cancelada']);
            }

            $vendida = OrdenItem::where('orden_id', $orden->id)->whereNotNull('venta_id')->exists();
            $orden->update([
                'estado'             => $vendida ? 'cerrada' : 'cancelada',
                'cerrada_at'         => now(),
                'cancelada_por'      => $porCobrar->isNotEmpty() ? $usuario->id : null,
                'motivo_cancelacion' => $porCobrar->isNotEmpty() ? $motivo : null,
            ]);

            if ($porCobrar->isNotEmpty()) {
                AuditoriaService::registrar('mesas', 'cerrar_con_pendientes', $orden, null, [
                    'terminal'       => $orden->terminal->nombre,
                    'productos'      => $porCobrar->map(fn ($i) => (float) $i->cantidad . ' ' . $i->descripcion())->all(),
                    'monto'          => round($porCobrar->sum(fn ($i) => $i->subtotal()), 2),
                    'autorizado_por' => $autorizador?->nombre,
                ], $motivo);
            }

            return $orden;
        });
    }

    // Pasa la orden completa a otra mesa libre
    public static function trasladar(Orden $orden, Terminal $destino, Administrador $usuario): Orden
    {
        if (!$destino->activo || $destino->tipo === 'caja') {
            throw new NegocioException('No se puede trasladar a esa terminal');
        }

        try {
            return DB::transaction(function () use ($orden, $destino, $usuario) {
                $orden = self::bloquear($orden);
                if (Orden::where('terminal_id', $destino->id)->where('estado', 'abierta')->exists()) {
                    throw new NegocioException("{$destino->nombre} está ocupada");
                }
                $origen = $orden->terminal->nombre;
                $orden->update(['terminal_id' => $destino->id]);
                AuditoriaService::registrar('mesas', 'trasladar', $orden, ['terminal' => $origen], ['terminal' => $destino->nombre], "Orden {$orden->numero}", $usuario->id);

                return $orden;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new NegocioException("{$destino->nombre} está ocupada");
        }
    }

    /**
     * Al anular una venta que salió de una orden, sus productos vuelven a
     * quedar por cobrar. Si la orden se había cerrado y la mesa sigue libre,
     * se reabre.
     */
    public static function alAnularVenta(Venta $venta): void
    {
        if (!$venta->orden_id) {
            return;
        }

        $items = OrdenItem::where('venta_id', $venta->id)->get();
        OrdenItem::whereIn('id', $items->pluck('id'))->update(['venta_id' => null]);
        OrdenTicket::whereIn('id', $items->pluck('ticket_id')->unique())->update(['estado' => 'abierto']);

        $orden = Orden::whereKey($venta->orden_id)->lockForUpdate()->first();
        if ($orden->estado === 'cerrada' && !Orden::where('terminal_id', $orden->terminal_id)->where('estado', 'abierta')->exists()) {
            $orden->update(['estado' => 'abierta', 'cerrada_at' => null]);
        }
    }
}
