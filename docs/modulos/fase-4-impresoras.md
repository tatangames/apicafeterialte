# Fase 4 — Impresoras

Estado: terminada el 2026-11-01. Plan general: [AUDITORIA_Y_PLAN.md](../AUDITORIA_Y_PLAN.md). Fase anterior: [fase-3-pos.md](fase-3-pos.md).

## Qué cambió

**Impresoras** (migración `2026_11_01_200001`):
- Se agregaron:
  - `conexion`: `red` (TCP directo, puerto 9100) o `windows` (USB en el servidor);
  - `puerto` y `nombre_windows`;
  - `ancho_caracteres`: 48 para 80 mm, 32 para 58 mm;
  - `uso`: `ticket`, `comanda` o `ambos`;
  - `predeterminada` y `cortar_papel`.
- La IP y el `ip_servidor` pasan a ser opcionales.
- Se conserva `cash_drawer` (pin 2 o pin 5).
- **Cambio del 2026-11-02:** se quitó `impresoras_categorias`. Las comandas se reparten por área de preparación (Fase 12): categoría → área → impresora del área.

**`trabajos_impresion`:** cada impresión queda registrada con su estado (`pendiente`, `impreso` o `error`), el error, el número de intentos y el contenido (en base64), para poder reintentarla.

**Armado del documento** (`app/Support/Impresion/`):
- `EscPos` arma los comandos ESC/POS sin librerías externas: alinear, negrita, tamaño doble, columnas, párrafos, corte parcial y pulso del cajón.
- El texto se convierte a la página de códigos CP850, así salen bien las tildes, la ñ y los signos ¿ y ¡.

**Envío a la impresora:**
- `TransporteRed`: abre una conexión al puerto 9100 de la impresora y espera hasta 3 segundos.
- `TransporteWindows`: manda los bytes en modo RAW a la impresora instalada en Windows, así funcionan el corte y el cajón. Reemplaza el método anterior, que imprimía texto por el driver y usaba la impresora fija `STAR1`.

**`ImpresionService`:**
- **Ticket:** usa los datos del negocio y abre el cajón solo si en la venta hubo efectivo y no es una reimpresión.
- **Comandas:** un ticket por área de preparación (la de la categoría de cada producto), sin precios, en la impresora del área o en la de comandas predeterminada. Funciona para ventas y precuentas, con las notas de cada producto.
- **Cierre de caja:** incluye el desglose por forma de pago y por empleado, la diferencia y el conteo.
- **Otros:** prueba de impresión, abrir el cajón y reintentar un trabajo (en la misma impresora o en otra).
- **Qué impresora se usa:** la asignada a la caja; si no tiene, la predeterminada.

**Imprimir nunca bloquea una venta.** La venta se registra primero y el ticket se pide después. Si la impresora falla, el POS muestra el motivo y ofrece reintentar o imprimir desde el navegador (el respaldo de 80 mm de las fases 2 y 3).

## Endpoints

| Ruta | Permiso |
|---|---|
| `POST /impresion/ventas/{venta}` `{caja_id?, copia?, comandas?}` | `pos.vender` o `pos.historial` |
| `POST /impresion/pedidos/{pedido}/comandas` | `pos.precuentas` |
| `POST /impresion/turnos/{turno}` | `caja.cerrar`, `caja.historial` o `caja.arqueo` |
| `POST /impresion/cajon` `{caja_id, motivo}` (queda en auditoría) | `caja.movimientos` |
| `GET /impresion/trabajos?estado=error` | `impresoras.gestionar` |
| `POST /impresion/trabajos/{id}/reintentar` `{impresora_id?}` | `impresoras.gestionar` o `pos.vender` |
| `POST /admin/impresora/{id}/test` (responde 422 con el motivo si falla) | `impresoras.gestionar` |

**Validaciones al guardar una impresora:**
- La IP (válida) es obligatoria en conexión de red.
- El nombre de Windows es obligatorio en conexión por Windows.
- Solo puede haber una predeterminada.

Las altas, cambios y bajas de impresoras quedan en auditoría.

## Frontend

**Configuración → Impresoras:**
- Tarjetas con la conexión, el papel, el uso, el cajón y las cajas que la usan.
- Botón «Imprimir prueba», que muestra el resultado o el error.
- Panel «Impresiones con error», con «Reintentar» o «Enviar a otra impresora».

**POS:** al cobrar se imprime el ticket (y las comandas) sin esperar. La ventana del cambio muestra «✓ Ticket impreso», o el error con «Reintentar» e «Imprimir desde el navegador». Además tiene «Imprimir otra vez», que imprime una copia.

**Otras pantallas** (si la térmica falla, se abre la impresión del navegador):
- Historial de ventas: «Reimprimir ticket», que imprime una copia.
- Corte X, cierre de caja y reporte de turno: se imprimen en la térmica.

**Caja:** botón «Abrir cajón», que pide motivo y queda en auditoría.

**Precuentas:** botón «Comanda» para enviar los productos a preparación.

## Pruebas

**Backend:** `php artisan test` corre 103 pruebas (443 aserciones). [ImpresionTest.php](../../tests/Feature/ImpresionTest.php) agrega 14 y usa un transporte falso:
- **Ticket:** su contenido, el pulso del cajón, el corte, que la copia no abre el cajón, que el pago con tarjeta no lo abre.
- **Selección de impresora:** la de la caja antes que la predeterminada; mensaje claro si no hay impresora.
- **Errores:** impresora apagada y reintento, permiso para ver los trabajos con error.
- **Comandas:** reparto por área y comandas de precuenta con notas.
- **Otros documentos:** cierre de caja, botón de prueba con error, cajón con motivo y auditoría.
- **Configuración y codificación:** validaciones de la impresora, alineación de columnas y CP850.

**Navegador**, con una impresora de red simulada (un servidor TCP local en 127.0.0.1:9100 que guarda lo que recibe):
1. Alta de la impresora y prueba: llegó con tildes, el pulso del cajón en el pin 2 y el corte.
2. Venta T-000005 en el POS: el ticket se imprimió solo, con el cajón.
3. Con la impresora apagada: se mostró el motivo con «Reintentar» e «Imprimir desde el navegador».
4. Al encenderla y reintentar: se imprimió la copia, sin abrir el cajón.

## Pendiente o a considerar

- **Windows (USB):** `TransporteWindows` no se probó con una impresora real conectada al servidor. Hay que hacer la primera prueba en la panadería con el botón «Imprimir prueba».
- **Logo:** el logo del negocio todavía no se imprime en la térmica (sí aparece en el ticket del navegador). Requiere convertir la imagen al formato de bits de ESC/POS.
- **Sin servidor:** si el servidor no responde, la térmica no recibe nada; en ese caso el ticket se imprime desde el navegador. Esto entra en la Fase 5.
- **Datos de prueba:** quedó en `posdb` la impresora «Mostrador» apuntando a 127.0.0.1:9100. Hay que cambiarle la IP por la de la impresora real.
