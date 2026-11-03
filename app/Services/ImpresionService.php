<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Impresora;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Support\Impresion\EscPos;
use App\Support\Impresion\ImpresionException;
use App\Support\Impresion\StarRaster;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use App\Support\Impresion\TransporteWindows;

/**
 * Arma los documentos (ticket, comanda, cierre, prueba) en ESC/POS, los envía
 * a la impresora y deja cada intento en trabajos_impresion para poder ver los
 * errores y reintentar. Imprimir nunca bloquea una venta: si falla, el POS
 * ofrece imprimir desde el navegador.
 */
class ImpresionService
{
    // ── Selección de impresora ──────────────────────────────────

    /**
     * Impresora de tickets para una caja: la asignada a la caja o, si no tiene,
     * la marcada como predeterminada.
     */
    public static function impresoraDeTickets(?int $cajaId): ?Impresora
    {
        if ($cajaId) {
            $caja = Caja::with('impresora')->find($cajaId);
            if ($caja?->impresora && $caja->impresora->activo && $caja->impresora->imprimeTickets()) {
                return $caja->impresora;
            }
        }

        return Impresora::where('activo', true)
            ->whereIn('uso', ['ticket', 'ambos'])
            ->orderByDesc('predeterminada')
            ->orderBy('id')
            ->first();
    }

    // ── Documentos ─────────────────────────────────────────────

    /**
     * Ticket de venta (y comandas por categoría si se piden).
     * @return TrabajoImpresion[]
     */
    public static function imprimirVenta(Venta $venta, ?int $cajaId, ?int $usuarioId, bool $copia = false, bool $conComandas = false): array
    {
        $trabajos = [];
        $impresora = self::impresoraDeTickets($cajaId ?? $venta->caja_id);

        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        $venta->loadMissing(['detalles', 'pagos.metodoPago', 'usuario', 'caja', 'cliente', 'pedido', 'cuentaCobrar']);

        $doc = self::ticket($venta, $impresora, $copia);

        // El cajón se abre solo en la venta original pagada (en parte) en efectivo
        $conEfectivo = $venta->pagos->contains(fn ($p) => $p->metodoPago?->afecta_efectivo);
        if (!$copia && $conEfectivo && $impresora->cash_drawer !== 'ninguno') {
            $doc->abrirCajon($impresora->cash_drawer);
        }

        $trabajos[] = self::enviar($impresora, $doc, 'ticket', 'venta', $venta->id, $usuarioId);

        if ($conComandas && !$copia) {
            $trabajos = array_merge($trabajos, self::comandas($venta->detalles->map(fn ($d) => [
                'producto_id' => $d->producto_id,
                'nombre'      => $d->nombre_producto,
                'cantidad'    => (float) $d->cantidad,
                'unidad'      => $d->unidad,
                'notas'       => null,
            ])->all(), "Venta {$venta->numero}", 'venta', $venta->id, $usuarioId, $impresora));
        }

        return $trabajos;
    }

    /**
     * Comandas de una precuenta o pedido: un ticket por área de preparación.
     * @return TrabajoImpresion[]
     */
    public static function imprimirComandasPedido(Pedido $pedido, ?int $usuarioId): array
    {
        $pedido->loadMissing('detalles');

        $trabajos = self::comandas($pedido->detalles->map(fn ($d) => [
            'producto_id' => $d->producto_id,
            'nombre'      => $d->nombre_producto,
            'cantidad'    => (float) $d->cantidad,
            'unidad'      => null,
            'notas'       => $d->notas,
        ])->all(), "{$pedido->numero} · {$pedido->nombre}", 'pedido', $pedido->id, $usuarioId, self::impresoraDeTickets(null));

        if (!$trabajos) {
            throw new NegocioException('No hay impresora de comandas ni de tickets configurada');
        }

        return $trabajos;
    }

    /**
     * Comanda de una mesa: cada área (Cocina, Barra) sale en su propio ticket, con
     * el nombre del área en grande, para entregarlo a quien prepara. Va a la impresora
     * del área; si no tiene, a la de comandas o de tickets (una sola tablet con una
     * impresora). Los extras y las notas van debajo de cada línea.
     * @return TrabajoImpresion[]
     */
    public static function imprimirComanda(\App\Models\Comanda $comanda, ?int $usuarioId, bool $reimpresion = false): array
    {
        $comanda->loadMissing(['orden.terminal', 'area.impresora', 'usuario:id,nombre', 'items' => fn ($q) => $q->where('estado', '!=', 'cancelado')]);

        $items = $comanda->items->map(fn ($i) => [
            'producto_id' => $i->producto_id,
            'nombre'      => $i->nombre_producto,
            'cantidad'    => (float) $i->cantidad,
            'unidad'      => null,
            'notas'       => collect($i->modificadores ?? [])->map(fn ($m) => "+ {$m['opcion']}")
                ->when($i->notas, fn ($c) => $c->push($i->notas))->join(' · ') ?: null,
        ])->all();

        if (!$items) {
            return [];
        }

        $titulo = "{$comanda->orden->terminal->nombre} · #{$comanda->numero}" . ($reimpresion ? ' (COPIA)' : '');
        $encabezado = mb_strtoupper($comanda->area?->nombre ?? 'Comanda');
        $extra = array_values(array_filter([
            $comanda->usuario ? "Mesero: {$comanda->usuario->nombre}" : null,
            $comanda->orden->comensales ? "{$comanda->orden->comensales} persona(s)" : null,
            $comanda->notas ? "Nota: {$comanda->notas}" : null,
        ]));

        $impresora = self::impresoraDeArea($comanda->area)
            ?? self::impresoraDeTickets($comanda->orden->terminal->caja_id);

        return $impresora
            ? [self::enviar($impresora, self::comanda($items, $titulo, $impresora, $encabezado, $extra), 'comanda', 'comanda', $comanda->id, $usuarioId)]
            : [];
    }

    /**
     * Precuenta de una mesa (toda la orden o una cuenta) en la impresora de tickets.
     * Lleva lo que falta por cobrar; no es comprobante de pago. La usa la app de la tablet.
     */
    public static function imprimirPrecuenta(\App\Models\Orden $orden, ?int $ticketId, ?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $orden->loadMissing(['terminal', 'tickets', 'items' => fn ($q) => $q->where('estado', '!=', 'cancelado')->whereNull('venta_id')]);
        $impresora = self::impresoraDeTickets($cajaId ?? $orden->terminal->caja_id);
        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        $items = $orden->items->when($ticketId, fn ($c) => $c->where('ticket_id', $ticketId));
        if ($items->isEmpty()) {
            throw new NegocioException('No hay nada por cobrar en esta cuenta');
        }

        $negocio = ConfiguracionNegocio::actual();
        $dinero = fn ($v) => '$' . number_format((float) $v, 2);
        $cuenta = $ticketId ? $orden->tickets->firstWhere('id', $ticketId)?->nombre : null;

        $doc = new EscPos($impresora->ancho_caracteres);
        $doc->alinear('centro')->negrita()->linea($negocio->nombre)->grande(true, false)->linea('PRECUENTA')->normal()
            ->alinear('izquierda')->separador()
            ->columnas($orden->terminal->nombre . ($cuenta ? " · {$cuenta}" : ''), now()->format('d/m/Y H:i'))
            ->separador();

        $total = 0.0;
        foreach ($items as $i) {
            $cantidad = rtrim(rtrim(number_format((float) $i->cantidad, 3, '.', ''), '0'), '.');
            $extras = collect($i->modificadores ?? [])->pluck('opcion')->join(', ');
            $subtotal = round((float) $i->cantidad * (float) $i->precio_unitario, 2);
            $total += $subtotal;
            $doc->parrafo($i->nombre_producto . ($extras ? " ({$extras})" : ''))
                ->columnas("  {$cantidad} x " . $dinero($i->precio_unitario), $dinero($subtotal));
        }

        $doc->separador()->negrita()->grande(true, false)->columnas('TOTAL', $dinero($total))->normal()
            ->alinear('centro')->separador()->linea('Este documento no es comprobante de pago');

        return self::enviar($impresora, $doc, 'precuenta', 'orden', $orden->id, $usuarioId);
    }

    public static function imprimirCierre(TurnoCaja $turno, ?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $impresora = self::impresoraDeTickets($cajaId ?? $turno->caja_id);
        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        return self::enviar($impresora, self::cierre($turno, $impresora), 'cierre', 'turno', $turno->id, $usuarioId);
    }

    public static function prueba(Impresora $impresora, ?int $usuarioId): TrabajoImpresion
    {
        $negocio = ConfiguracionNegocio::actual();
        $doc = new EscPos($impresora->ancho_caracteres);

        $doc->alinear('centro')->negrita()->grande()->linea($negocio->nombre)->normal()
            ->linea('PRUEBA DE IMPRESIÓN')
            ->separador()
            ->alinear('izquierda')
            ->columnas('Impresora', $impresora->alias)
            ->columnas('Conexión', $impresora->conexion === 'red'
                ? "{$impresora->ip_impresora}:{$impresora->puerto}"
                : (string) $impresora->nombre_windows)
            ->columnas('Papel', $impresora->ancho_caracteres >= 48 ? '80 mm' : '58 mm')
            ->columnas('Fecha', now()->format('d/m/Y H:i'))
            ->separador()
            ->linea('Acentos: áéíóú ÁÉÍÓÚ ñÑ ü ¿? ¡!')
            // Regla del ancho exacto del papel: debe caber en una sola línea
            ->linea(substr(str_repeat('1234567890', 10), 0, $impresora->ancho_caracteres))
            ->separador()
            ->alinear('centro')->negrita()->linea('¡Impresión correcta!')->normal();

        if ($impresora->cash_drawer !== 'ninguno') {
            $doc->abrirCajon($impresora->cash_drawer);
        }

        return self::enviar($impresora, $doc, 'prueba', null, null, $usuarioId);
    }

    public static function abrirCajon(?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $impresora = self::impresoraDeTickets($cajaId);
        if (!$impresora || $impresora->cash_drawer === 'ninguno') {
            throw new NegocioException('La impresora de esta caja no tiene cajón de dinero configurado');
        }

        $doc = (new EscPos($impresora->ancho_caracteres))->abrirCajon($impresora->cash_drawer);

        return self::enviar($impresora, $doc, 'cajon', null, null, $usuarioId, cortar: false);
    }

    /**
     * Vuelve a enviar un trabajo que falló (mismo contenido, a la misma impresora
     * o a otra si se indica).
     */
    public static function reintentar(TrabajoImpresion $trabajo, ?Impresora $otra = null): TrabajoImpresion
    {
        $impresora = $otra ?? $trabajo->impresora;
        if (!$impresora || !$impresora->activo) {
            throw new NegocioException('La impresora de este trabajo ya no está disponible: elige otra');
        }

        $trabajo->impresora_id = $impresora->id;
        self::transmitir($trabajo, $impresora, base64_decode($trabajo->contenido));

        return $trabajo;
    }

    // ── Plantillas ─────────────────────────────────────────────

    public static function ticket(Venta $venta, Impresora $impresora, bool $copia = false): EscPos
    {
        $negocio = ConfiguracionNegocio::actual();
        $doc = new EscPos($impresora->ancho_caracteres);
        $dinero = fn ($v) => '$' . number_format((float) $v, 2);

        $doc->alinear('centro')->negrita()->grande()->linea($negocio->nombre)->normal();
        foreach ([$negocio->razon_social, $negocio->direccion] as $dato) {
            if ($dato) {
                $doc->parrafo($dato);
            }
        }
        if ($negocio->telefono) {
            $doc->linea("Tel. {$negocio->telefono}");
        }

        $doc->alinear('izquierda')->separador()
            ->columnas("Ticket {$venta->numero}", $venta->fecha_venta->format('d/m/Y H:i'))
            ->columnas('Atendió: ' . ($venta->usuario?->nombre ?? ''), $venta->caja?->nombre ?? '');

        if ($copia) {
            $doc->alinear('centro')->negrita()->linea('*** COPIA ***')->normal()->alinear('izquierda');
        }
        if ($venta->estado === 'anulada') {
            $doc->alinear('centro')->negrita()->linea('*** ANULADA ***')->normal()->alinear('izquierda');
        }
        if ($venta->cliente) {
            $doc->parrafo('Cliente: ' . $venta->cliente->nombre . ($venta->cliente->documento ? " ({$venta->cliente->documento})" : ''));
        }
        if ($venta->pedido?->tipo === 'especial') {
            $doc->linea("Pedido {$venta->pedido->numero}");
        }

        $doc->separador();
        foreach ($venta->detalles as $d) {
            $cantidad = rtrim(rtrim(number_format((float) $d->cantidad, 3, '.', ''), '0'), '.');
            $unidad = $d->unidad && $d->unidad !== 'u' ? " {$d->unidad}" : '';
            $doc->parrafo($d->nombre_producto)
                ->columnas("  {$cantidad}{$unidad} x " . $dinero($d->precio_unitario), $dinero((float) $d->cantidad * (float) $d->precio_unitario));
            if ((float) $d->descuento > 0) {
                $doc->columnas('  Descuento', '-' . $dinero($d->descuento));
            }
        }

        $doc->separador();
        if ((float) $venta->descuento_total > 0) {
            $doc->columnas('Subtotal', $dinero((float) $venta->subtotal + (float) $venta->descuento_lineas));
            if ((float) $venta->descuento_lineas > 0) {
                $doc->columnas('Descuentos', '-' . $dinero($venta->descuento_lineas));
            }
            if ((float) $venta->descuento_global > 0) {
                $doc->columnas('Descuento general', '-' . $dinero($venta->descuento_global));
            }
        }

        $doc->negrita()->grande(true, false)->columnas('TOTAL', $dinero($venta->total))->normal()
            ->separador();

        if ((float) $venta->anticipo_aplicado > 0) {
            $doc->columnas('Anticipo recibido', $dinero($venta->anticipo_aplicado));
        }
        foreach ($venta->pagos as $p) {
            $doc->columnas($p->metodoPago?->nombre ?? 'Pago', $dinero($p->monto_recibido ?? $p->monto));
            if ($p->referencia) {
                $doc->columnas('  Ref.', $p->referencia);
            }
        }
        if ((float) $venta->cambio > 0) {
            $doc->negrita()->columnas('Cambio', $dinero($venta->cambio))->normal();
        }
        if ($venta->cuentaCobrar) {
            // Venta al crédito: el cliente firma el ticket
            $doc->linea('Credito: vence el ' . $venta->cuentaCobrar->fecha_vencimiento->format('d/m/Y'))
                ->linea('')->linea('')->alinear('centro')
                ->linea('______________________________')->linea('Firma del cliente')->alinear('izquierda');
        }

        $doc->separador()->alinear('centro');
        if ($negocio->mensaje_ticket) {
            $doc->parrafo($negocio->mensaje_ticket);
        }
        $doc->linea(strtoupper(substr($venta->uuid, 0, 8)));

        return $doc;
    }

    /**
     * Comanda: lista grande y simple para preparar (sin precios).
     * @param array $items [['producto_id','nombre','cantidad','unidad','notas'], ...]
     */
    public static function comanda(array $items, string $titulo, Impresora $impresora, string $encabezado = 'COMANDA', array $extra = []): EscPos
    {
        $doc = new EscPos($impresora->ancho_caracteres);

        $doc->alinear('centro')->negrita()->grande()->linea($encabezado)->normal()
            ->negrita()->linea($titulo)->normal()
            ->linea(now()->format('d/m/Y H:i'));
        foreach ($extra as $linea) {
            $doc->parrafo($linea, $impresora->ancho_caracteres);
        }
        $doc->alinear('izquierda')->separador();

        foreach ($items as $i) {
            $cantidad = rtrim(rtrim(number_format((float) $i['cantidad'], 3, '.', ''), '0'), '.');
            $unidad = $i['unidad'] && $i['unidad'] !== 'u' ? " {$i['unidad']}" : '';
            $doc->negrita()->grande(true, false)->parrafo("{$cantidad}{$unidad}  {$i['nombre']}", $impresora->ancho_caracteres)->normal();
            if (!empty($i['notas'])) {
                $doc->parrafo("   > {$i['notas']}");
            }
        }

        return $doc->separador();
    }

    public static function cierre(TurnoCaja $turno, Impresora $impresora): EscPos
    {
        $negocio = ConfiguracionNegocio::actual();
        $turno->loadMissing(['caja', 'usuarioApertura', 'usuarioCierre', 'arqueos']);
        $resumen = CajaService::resumen($turno);
        $dinero = fn ($v) => '$' . number_format((float) $v, 2);

        $doc = new EscPos($impresora->ancho_caracteres);
        $doc->alinear('centro')->negrita()->linea($negocio->nombre)
            ->grande(true, false)->linea($turno->estaAbierto() ? 'CORTE PARCIAL' : 'CIERRE DE CAJA')->normal()
            ->linea("{$turno->caja?->nombre} · Turno #{$turno->id}")
            ->alinear('izquierda')->separador()
            ->columnas('Apertura', $turno->abierto_at->format('d/m/Y H:i'))
            ->columnas('Abrió', $turno->usuarioApertura?->nombre ?? '');

        if ($turno->cerrado_at) {
            $doc->columnas('Cierre', $turno->cerrado_at->format('d/m/Y H:i'))
                ->columnas('Cerró', $turno->usuarioCierre?->nombre ?? '');
        }

        $doc->separador()
            ->columnas('Fondo inicial', $dinero($resumen['fondo_inicial']))
            ->columnas('Ventas', $dinero($resumen['totales']['ventas']));
        if ($resumen['totales']['devoluciones'] > 0) {
            $doc->columnas('Devoluciones', '-' . $dinero($resumen['totales']['devoluciones']));
        }
        $doc->columnas('Ingresos', $dinero($resumen['totales']['ingresos']))
            ->columnas('Egresos', '-' . $dinero($resumen['totales']['egresos']))
            ->separador()->negrita()->linea('Por forma de pago')->normal();

        foreach ($resumen['por_metodo'] as $m) {
            $doc->columnas($m['nombre'], $dinero($m['neto']));
        }

        if ($resumen['ventas_por_usuario']) {
            $doc->separador()->negrita()->linea('Ventas por empleado')->normal();
            foreach ($resumen['ventas_por_usuario'] as $v) {
                $doc->columnas("{$v['usuario']} ({$v['cantidad']})", $dinero($v['total']));
            }
        }

        $doc->separador()->negrita()->columnas('Efectivo esperado', $dinero($turno->efectivo_esperado ?? $resumen['efectivo_esperado']));
        if ($turno->efectivo_contado !== null) {
            $diferencia = (float) $turno->diferencia;
            $doc->columnas('Efectivo contado', $dinero($turno->efectivo_contado))
                ->columnas($diferencia < 0 ? 'FALTANTE' : ($diferencia > 0 ? 'SOBRANTE' : 'DIFERENCIA'), $dinero(abs($diferencia)));
        }
        $doc->normal();

        $conteo = $turno->arqueos->firstWhere('tipo', 'cierre')?->denominaciones;
        if ($conteo) {
            $doc->separador()->linea('Conteo');
            krsort($conteo, SORT_NUMERIC);
            foreach ($conteo as $valor => $cantidad) {
                $doc->columnas("  {$cantidad} x " . $dinero($valor), $dinero((float) $valor * $cantidad));
            }
        }

        if ($turno->observaciones_cierre) {
            $doc->separador()->parrafo("Obs.: {$turno->observaciones_cierre}");
        }

        // Lo que el siguiente turno tiene que comprar: solo lo que quedó en su mínimo o debajo
        $cantidad = fn ($v, $u) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . ($u ? " {$u}" : '');
        $doc->separador()->negrita()->linea('POR COMPRAR')->normal();
        if (!$resumen['por_comprar']) {
            $doc->linea('Nada bajo el mínimo');
        }
        foreach ($resumen['por_comprar'] as $p) {
            $doc->columnas($p['producto'], 'hay ' . $cantidad($p['stock_actual'], $p['unidad']) . ' / mín ' . $cantidad($p['stock_minimo'], null));
        }

        $mitad = intdiv($impresora->ancho_caracteres, 2) - 2;
        $doc->avanzar(3)
            ->linea(str_repeat('_', $mitad) . '    ' . str_repeat('_', $mitad))
            ->linea(str_pad('Cajero', $mitad + 4) . 'Supervisor');

        return $doc;
    }

    // ── Envío ──────────────────────────────────────────────────

    /**
     * Reparte los productos por área de preparación (la de su categoría) y manda un
     * ticket por área a su impresora. Todo sale en comanda: lo que no tiene área va
     * en una comanda general. Sin impresora de comandas se usa $respaldo (la de tickets).
     * @return TrabajoImpresion[]
     */
    private static function comandas(array $items, string $titulo, string $documentoTipo, int $documentoId, ?int $usuarioId, ?Impresora $respaldo = null): array
    {
        $areaPorProducto = Producto::with('categorias.area.impresora')
            ->whereIn('id', collect($items)->pluck('producto_id'))
            ->get()
            ->mapWithKeys(fn ($p) => [$p->id => $p->categorias->map(fn ($c) => $c->area)->first(fn ($a) => $a && $a->activo)]);

        $trabajos = [];
        foreach (collect($items)->groupBy(fn ($i) => $areaPorProducto[$i['producto_id']]?->id) as $areaId => $delArea) {
            $area = $areaId ? $areaPorProducto[$delArea->first()['producto_id']] : null;
            $impresora = self::impresoraDeArea($area) ?? $respaldo;
            if ($impresora) {
                $trabajos[] = self::enviar($impresora, self::comanda($delArea->all(), $titulo, $impresora, mb_strtoupper($area?->nombre ?? 'Comanda')),
                    'comanda', $documentoTipo, $documentoId, $usuarioId);
            }
        }

        return $trabajos;
    }

    // La impresora del área; si no tiene, la de comandas predeterminada
    private static function impresoraDeArea(?\App\Models\AreaPreparacion $area): ?Impresora
    {
        $propia = $area?->impresora;
        if ($propia && $propia->activo) {
            return $propia;
        }

        return Impresora::where('activo', true)->whereIn('uso', ['comanda', 'ambos'])
            ->orderByDesc('predeterminada')->orderBy('id')->first();
    }

    private static function enviar(Impresora $impresora, EscPos $doc, string $tipo, ?string $documentoTipo, ?int $documentoId, ?int $usuarioId, bool $cortar = true): TrabajoImpresion
    {
        if ($cortar && $impresora->cortar_papel) {
            $doc->cortar();
        } elseif ($cortar) {
            $doc->avanzar(4);
        }

        $trabajo = TrabajoImpresion::create([
            'impresora_id'   => $impresora->id,
            'tipo'           => $tipo,
            'documento_tipo' => $documentoTipo,
            'documento_id'   => $documentoId,
            'estado'         => 'pendiente',
            'contenido'      => base64_encode($doc->bytes()),
            'usuario_id'     => $usuarioId,
        ]);

        self::transmitir($trabajo, $impresora, $doc->bytes());

        return $trabajo;
    }

    private static function transmitir(TrabajoImpresion $trabajo, Impresora $impresora, string $bytes): void
    {
        $trabajo->intentos++;

        try {
            // Se guarda siempre en ESC/POS; las Star TSP100 lo reciben convertido a imagen
            if ($impresora->tipo_impresion === 'star_raster') {
                $bytes = StarRaster::desdeEscPos($bytes, $impresora->ancho_caracteres);
            }
            self::transporte($impresora)->enviar($impresora, $bytes);
            $trabajo->estado = 'impreso';
            $trabajo->error = null;
            $trabajo->impreso_at = now();
        } catch (ImpresionException $e) {
            $trabajo->estado = 'error';
            $trabajo->error = mb_substr($e->getMessage(), 0, 500);
        }

        $trabajo->save();
    }

    private static function transporte(Impresora $impresora): Transporte
    {
        return $impresora->conexion === 'windows' ? app(TransporteWindows::class) : app(TransporteRed::class);
    }
}
