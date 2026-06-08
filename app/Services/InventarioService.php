<?php

namespace App\Services;

use App\Models\EntradaInventario;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use Illuminate\Support\Facades\DB;
use App\Models\EntradaInventarioDetalle;

class InventarioService
{
    /**
     * Registra una entrada/salida/ajuste con su desglose de productos.
     *
     * @param string $tipo        entrada|salida|ajuste|produccion|venta
     * @param string $fecha       Y-m-d
     * @param array  $items       [['producto_id' => x, 'cantidad' => y], ...]
     * @param string|null $descripcion
     * @param int|null    $usuarioId
     */
    public static function registrarEntrada(
        string  $tipo,
        string  $fecha,
        array   $items,
        ?string $descripcion = null,
        ?int    $usuarioId   = null
    ): EntradaInventario {
        return DB::transaction(function () use (
            $tipo, $fecha, $items, $descripcion, $usuarioId
        ) {
            // 1) Crear cabecera
            $entrada = EntradaInventario::create([
                'fecha'       => $fecha,
                'tipo'        => $tipo,
                'descripcion' => $descripcion,
                'usuario_id'  => $usuarioId,
            ]);

            // 2) Procesar cada producto del desglose
            foreach ($items as $item) {
                $productoId = $item['producto_id'];
                $cantidad   = (float) $item['cantidad'];

                // Obtener o crear fila de inventario
                $inventario = Inventario::firstOrCreate(
                    ['producto_id' => $productoId],
                    ['stock_actual' => 0, 'stock_minimo' => 0]
                );

                $stockAnterior = (float) $inventario->stock_actual;

                // Calcular nuevo stock según tipo
                $stockResultante = match($tipo) {
                    'entrada', 'produccion' => $stockAnterior + $cantidad,
                    'salida',  'venta'      => $stockAnterior - $cantidad,
                    'ajuste'                => $cantidad,
                };

                // Actualizar stock
                $inventario->stock_actual = $stockResultante;
                $inventario->save();

                // Guardar detalle de la entrada
                EntradaInventarioDetalle::create([
                    'entrada_id'       => $entrada->id,
                    'producto_id'      => $productoId,
                    'cantidad'         => $cantidad,
                    'stock_anterior'   => $stockAnterior,
                    'stock_resultante' => $stockResultante,
                ]);

                // Registrar movimiento (historial completo)
                MovimientoInventario::create([
                    'producto_id'      => $productoId,
                    'tipo'             => $tipo,
                    'cantidad'         => $cantidad,
                    'stock_anterior'   => $stockAnterior,
                    'stock_resultante' => $stockResultante,
                    'motivo'           => $descripcion,
                    'referencia_id'    => $entrada->id,
                    'referencia_tipo'  => 'entrada_inventario',
                    'usuario_id'       => $usuarioId,
                ]);
            }

            return $entrada;
        });
    }
}
