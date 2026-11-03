<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\CuentaPagar;
use App\Models\MetodoPago;
use App\Models\PagoProveedor;
use App\Models\PagoProveedorDetalle;
use App\Models\Proveedor;
use App\Models\TurnoCaja;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CuentaPagarService
{
    /**
     * Registra un pago a un proveedor. Si sale de la caja, es un movimiento
     * "pago_proveedor" del turno abierto (y no puede superar el efectivo).
     * Se aplica a las cuentas indicadas o, si no se indican, de la más antigua
     * a la más reciente. Idempotente por uuid.
     *
     * $datos: uuid, monto, fecha?, origen (caja|externo), caja_id?, metodo_pago_id?, referencia?,
     *         aplicaciones? [['cuenta_pagar_id' | 'compra_id', 'monto'], ...]
     */
    public static function registrarPago(Proveedor $proveedor, array $datos, Administrador $usuario, ?string $concepto = null): PagoProveedor
    {
        if ($existente = PagoProveedor::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $monto = round((float) $datos['monto'], 2);
        $origen = $datos['origen'] ?? 'caja';

        try {
            return DB::transaction(function () use ($proveedor, $datos, $usuario, $monto, $origen, $concepto) {
                $proveedor = Proveedor::whereKey($proveedor->id)->lockForUpdate()->first();

                if ($monto <= 0) {
                    throw new NegocioException('El pago debe ser mayor a 0');
                }
                if ($monto > (float) $proveedor->saldo + 0.001) {
                    throw new NegocioException("A {$proveedor->nombre} se le deben $" . number_format((float) $proveedor->saldo, 2)
                        . ': el pago no puede ser mayor');
                }

                $metodo = !empty($datos['metodo_pago_id']) ? MetodoPago::find($datos['metodo_pago_id']) : MetodoPago::efectivo();
                if (!$metodo || !$metodo->activo || $metodo->tipo === 'credito') {
                    throw new NegocioException('Forma de pago no válida');
                }
                if ($metodo->requiere_referencia && empty($datos['referencia'])) {
                    throw new NegocioException("{$metodo->nombre} requiere número de referencia");
                }

                $turno = null;
                if ($origen === 'caja') {
                    $turno = TurnoCaja::where('caja_id', $datos['caja_id'] ?? 0)->where('estado', 'abierto')->first();
                    if (!$turno) {
                        throw new NegocioException('Abre el turno de caja para pagar desde la caja');
                    }
                }

                $pago = PagoProveedor::create([
                    'uuid'           => $datos['uuid'],
                    'numero'         => CorrelativoService::siguiente('pago_proveedor', 'PP'),
                    'proveedor_id'   => $proveedor->id,
                    'fecha'          => $datos['fecha'] ?? today()->toDateString(),
                    'monto'          => $monto,
                    'origen'         => $origen,
                    'turno_id'       => $turno?->id,
                    'metodo_pago_id' => $metodo->id,
                    'referencia'     => $datos['referencia'] ?? null,
                    'usuario_id'     => $usuario->id,
                ]);

                self::aplicar($pago, $proveedor, $monto, $datos['aplicaciones'] ?? null);

                $proveedor->update(['saldo' => round(max(0, (float) $proveedor->saldo - $monto), 2)]);

                if ($turno) {
                    CajaService::registrarMovimiento(
                        turno:         $turno,
                        tipo:          'pago_proveedor',
                        monto:         $monto,
                        concepto:      "Pago {$pago->numero} a {$proveedor->nombre}" . ($concepto ? " ({$concepto})" : ''),
                        usuarioId:     $usuario->id,
                        metodoPagoId:  $metodo->id,
                        referencia:    $datos['referencia'] ?? null,
                        documentoTipo: 'pago_proveedor',
                        documentoId:   $pago->id,
                    );
                }

                AuditoriaService::registrar('compras', 'pago_proveedor', $pago, null, [
                    'proveedor' => $proveedor->nombre,
                    'monto'     => $monto,
                    'origen'    => $origen,
                    'metodo'    => $metodo->nombre,
                ], $concepto ?? "Pago {$pago->numero}");

                return $pago;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existente = PagoProveedor::where('uuid', $datos['uuid'])->first()) {
                return $existente;
            }
            throw $e;
        }
    }

    private static function aplicar(PagoProveedor $pago, Proveedor $proveedor, float $monto, ?array $aplicaciones): void
    {
        $pendientes = CuentaPagar::where('proveedor_id', $proveedor->id)->where('estado', 'pendiente')
            ->orderBy('fecha_vencimiento')->orderBy('id')->lockForUpdate()->get();

        // Sin indicar cuentas: de la más antigua a la más reciente
        $plan = $aplicaciones
            ? collect($aplicaciones)->map(function ($a) use ($pendientes) {
                $cuenta = !empty($a['cuenta_pagar_id'])
                    ? $pendientes->firstWhere('id', (int) $a['cuenta_pagar_id'])
                    : $pendientes->firstWhere('compra_id', (int) ($a['compra_id'] ?? 0));
                if (!$cuenta) {
                    throw new NegocioException('Una de las cuentas a pagar no está pendiente o no es de este proveedor');
                }
                return [$cuenta, round((float) $a['monto'], 2)];
            })->all()
            : $pendientes->map(fn ($c) => [$c, null])->all();

        $restante = $monto;
        foreach ($plan as [$cuenta, $indicado]) {
            if ($restante <= 0) {
                break;
            }
            $aplicado = round(min($indicado ?? $restante, $restante, (float) $cuenta->saldo), 2);
            if ($indicado !== null && $indicado > (float) $cuenta->saldo + 0.001) {
                throw new NegocioException('El monto aplicado a una cuenta supera su saldo ($' . number_format((float) $cuenta->saldo, 2) . ')');
            }
            if ($aplicado <= 0) {
                continue;
            }

            $saldo = round((float) $cuenta->saldo - $aplicado, 2);
            PagoProveedorDetalle::create(['pago_id' => $pago->id, 'cuenta_pagar_id' => $cuenta->id, 'monto' => $aplicado]);
            $cuenta->update(['saldo' => $saldo, 'estado' => $saldo <= 0 ? 'pagada' : 'pendiente']);
            $restante = round($restante - $aplicado, 2);
        }

        if ($restante > 0.001) {
            throw new NegocioException('El pago ($' . number_format($monto, 2) . ') no coincide con lo aplicado a las cuentas');
        }
    }

    /**
     * Anula un pago: si salió de caja, el turno debe seguir abierto y el dinero
     * vuelve a la gaveta. Las cuentas recuperan su saldo.
     */
    public static function anularPago(PagoProveedor $pago, string $motivo, Administrador $usuario): PagoProveedor
    {
        return DB::transaction(function () use ($pago, $motivo, $usuario) {
            $pago = PagoProveedor::whereKey($pago->id)->lockForUpdate()->first();
            if ($pago->anulado) {
                throw new NegocioException('El pago ya está anulado');
            }

            if ($pago->origen === 'caja') {
                $turno = TurnoCaja::find($pago->turno_id);
                if (!$turno?->estaAbierto()) {
                    throw new NegocioException('El pago salió de un turno de caja ya cerrado: no se puede anular');
                }
                CajaService::anularPorDocumento($turno, 'pago_proveedor', $pago->id, "Pago {$pago->numero} anulado: {$motivo}", $usuario->id);
            }

            foreach ($pago->detalles as $d) {
                $cuenta = CuentaPagar::whereKey($d->cuenta_pagar_id)->lockForUpdate()->first();
                $cuenta->update([
                    'saldo'  => round((float) $cuenta->saldo + (float) $d->monto, 2),
                    'estado' => $cuenta->estado === 'anulada' ? 'anulada' : 'pendiente',
                ]);
            }

            $proveedor = Proveedor::whereKey($pago->proveedor_id)->lockForUpdate()->first();
            $proveedor->update(['saldo' => round((float) $proveedor->saldo + (float) $pago->monto, 2)]);

            $pago->update(['anulado' => true, 'anulado_por' => $usuario->id, 'anulado_at' => now(), 'motivo_anulacion' => $motivo]);

            AuditoriaService::registrar('compras', 'anular_pago_proveedor', $pago,
                ['monto' => (float) $pago->monto], ['anulado' => true], $motivo);

            return $pago;
        });
    }

    public static function formatoCuenta(CuentaPagar $c): array
    {
        $c->loadMissing('compra:id,numero,fecha,tipo_documento,numero_documento');

        return [
            'id'                => $c->id,
            'compra_id'         => $c->compra_id,
            'compra'            => $c->compra?->numero,
            'documento'         => $c->compra?->numero_documento ? strtoupper($c->compra->tipo_documento) . ' ' . $c->compra->numero_documento : null,
            'fecha'             => $c->compra?->fecha?->toDateString(),
            'monto'             => (float) $c->monto,
            'saldo'             => (float) $c->saldo,
            'fecha_vencimiento' => $c->fecha_vencimiento->toDateString(),
            'estado'            => $c->estado,
            'vencida'           => $c->estaVencida(),
            'dias_vencida'      => $c->estaVencida() ? (int) $c->fecha_vencimiento->diffInDays(today()) : 0,
        ];
    }
}
