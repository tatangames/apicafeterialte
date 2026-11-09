# Sistema POS Panadería Eduardo — Auditoría y plan de implementación

Fecha de auditoría: 2026-10-31
Proyectos: `apicafeterialte` (Laravel 13.14, Sanctum 4, Spatie Permission 8, Maatwebsite Excel 3.1, MySQL `posdb`) y `reactcafeterialte` (React 19, TypeScript 5.7, Vite 6, Tailwind 4, plantilla TailAdmin).

Alcance: una sola panadería. Sin multiempresa ni multisucursal (una panadería puede tener 1–2 cajas físicas; eso no es multisucursal y sí se contempla).

---

## 1. Auditoría del estado actual

### 1.1 Lo que existe y se conserva

| Área | Backend | Frontend | Estado |
|---|---|---|---|
| Login / logout / recuperar contraseña | `LoginApiController` (Sanctum, tokens) | `SignIn`, `ResetPassword*` | Funcional |
| Usuarios, roles y permisos | `Config/RolesApiController` (Spatie, guard `api`) | `RolesPermisos/*` | Funcional, sin autorización en backend |
| Categorías | `ConfiguracionApiController` | `Configuracion/Categorias` | Funcional |
| Unidades de medida | `ConfiguracionApiController` | `Configuracion/UnidadMedida` | Funcional (sin abreviatura ni conversión) |
| Proveedores | `ConfiguracionApiController` | `Configuracion/Proveedores` | Básico (solo nombre y notas) |
| Impresoras | `ConfiguracionApiController` + `impresoras_categorias` | `Configuracion/Impresoras` | Prueba de impresión vía spooler de Windows |
| Productos | `Productos/ProductosApiController` (imagen, SKU, categorías) | `Productos/*` | Funcional, **sin precio de venta** |
| Inventario | `InventarioApiController` + `InventarioService` (cabecera, detalle, movimientos) | `Inventario/*` | Funcional, base del kardex |

Patrones que se mantienen en todo el desarrollo nuevo:

- Backend: controladores `app/Http/Controllers/Api/<Modulo>/<X>ApiController.php`, lógica de negocio en `app/Services/<X>Service.php` con `DB::transaction`, validación con `validator()` y mensajes en español, respuesta `{ success, message, ... }`.
- Frontend: `pages/<Modulo>/*.tsx` + `hooks/<modulo>/use<X>Hook.ts` + funciones en `services/api.ts` + interfaces en `types/interfaces.ts`, `react-data-table-component`, `react-hot-toast`, `PermissionRoute` / `CanAccess`.
- Base de datos: nombres de tablas y columnas en español, `snake_case`, `softDeletes` en catálogos.

La base de datos está prácticamente vacía (2 administradores, 2 roles, 2 permisos; 0 productos), así que los cambios de esquema se pueden aplicar con migraciones nuevas sin riesgo de datos. No se modifican migraciones ya ejecutadas: todo cambio va en una migración nueva.

### 1.2 Hallazgos críticos (se corrigen en la Fase 0)

1. **Rutas sin autenticación.** `/productos/*` e `/inventario/*` están fuera del grupo `auth:sanctum` (`routes/api.php:20-28`). Cualquiera en la red puede crear productos y mover inventario. Además, como no hay usuario autenticado, `auth()->id()` es `null` y los movimientos se guardan sin usuario: se pierde la trazabilidad.
2. **Sin autorización en backend.** Spatie está instalado pero ninguna ruta usa `permission:`. Los permisos solo se revisan en React, y la mayoría de `PermissionRoute` no recibe permiso, así que dejan pasar a cualquier usuario autenticado. Un cajero podría llamar a la API de roles directamente.
3. **El login no revisa `administradores.estado`.** Un usuario desactivado puede iniciar sesión. Tampoco hay límite de intentos y el mensaje `EMAIL_NOT_FOUND` permite averiguar qué correos existen.
4. **Relación de usuario rota.** `EntradaInventario::usuario()` y `MovimientoInventario::usuario()` apuntan a `App\Models\User` (la tabla `users` no existe) y el controlador lee `->name`. Debe ser `Administrador` y `nombre`.
5. **Condición de carrera en stock.** `InventarioService::registrarEntrada` lee y escribe `stock_actual` sin `lockForUpdate()`. Dos ventas simultáneas del mismo pan pueden dejar el stock mal.
6. **Errores internos expuestos.** Varios controladores devuelven `$e->getMessage()` al cliente, y `APP_DEBUG=true`.
7. **`DB::commit()` / `DB::rollBack()` sin `beginTransaction()`** en `actualizarCategoria` y `actualizarUnidadMedida`.
8. **`Proveedor::compras()`** referencia la clase `Compra`, que no existe.
9. **El frontend no compila para producción.** `npm run build` (`tsc -b`) falla con 12 errores: props `type`/`maxLength` que no aceptan `Button`/`Input`, el tipo `Permiso` no exportado e imports sin usar en `AppHeader`.
10. **Impresión acoplada al servidor.** La prueba de impresión usa la impresora de Windows fija `'STAR1'`, ignora `ip_impresora`, escribe en `C:\Windows\Temp` y ejecuta PowerShell. El ticket dice "CAFETERIA EDUARDO".

### 1.3 Brechas funcionales

- `productos`: falta `precio_venta`, el tipo de producto para panadería (producto terminado / materia prima / reventa), código de barras, venta fraccionada (por peso) y orden y color para el POS. El tipo actual (`bien` / `servicio` / `bien_servicio`) no basta.
- `movimientos_inventario.tipo` solo admite `entrada, salida, ajuste, produccion, venta`. Faltan `compra`, `merma`, `devolucion`, `anulacion`, `consumo_produccion`. Falta el costo unitario por movimiento (necesario para el kardex valorizado).
- `inventario.stock_minimo` existe pero no hay pantalla ni endpoint para editarlo.
- `unidades_medida` no tiene abreviatura ni factor de conversión. En panadería las recetas usan gramos y la harina se compra por quintal o libra, así que sin conversión los costos de producción salen mal.
- No existen: ventas, pagos, caja y turnos, clientes, cuentas por cobrar y por pagar, compras, recetas, producción, mermas, gastos, reportes, auditoría ni modo offline.
- Frontend: `api.ts` recibe el `token` en cada función y no tiene interceptor para el 401, así que un token vencido deja al usuario en una pantalla rota. La ruta `/admin/inventario` está duplicada en `App.tsx`. `/admin/roles/:id/permisos` no tiene `PermissionRoute`. Quedan páginas y dependencias de la plantilla (Calendar, UiElements, fullcalendar, jvectormap, swiper).
- No hay pruebas automatizadas (solo los `ExampleTest`).
- Los listados devuelven todo con `->get()`. Para el kardex, el historial de ventas y los reportes hace falta paginación y filtro por fechas.

---

## 2. Decisiones de arquitectura

1. **Laravel decide, React muestra.** Totales, impuestos, descuentos, costos, stock y saldos se calculan en el backend. El frontend solo calcula una vista previa del carrito. Al registrar la venta, el backend recalcula desde las líneas.
2. **Transacciones y bloqueos.** Toda operación de venta, inventario, caja, compra, producción o abono corre dentro de `DB::transaction` con `lockForUpdate()` sobre `inventario`, `turnos_caja` y los saldos.
3. **Un solo punto de entrada al stock.** Toda variación de stock pasa por `InventarioService`, que siempre escribe en `movimientos_inventario` con `referencia_tipo` / `referencia_id`. El kardex sale de esa tabla.
4. **Idempotencia.** Ventas, abonos, movimientos de caja y pedidos llevan un `uuid` generado en el cliente, con índice único. Si llega dos veces, el backend devuelve el registro existente en lugar de duplicarlo. Esto es la base del modo offline.
5. **Correlativos sin huecos.** La tabla `correlativos` se actualiza con `lockForUpdate` para los números de ticket, órdenes de compra, etc.
6. **Snapshots.** El detalle de venta guarda nombre, precio y costo del producto en ese momento. Si el producto cambia después, el historial no se altera.
7. **Nada se borra.** Las ventas se anulan (con motivo, usuario y fecha) y las devoluciones son documentos propios que revierten stock y caja con su propio movimiento.
8. **Permisos `modulo.accion`.** Por ejemplo `pos.vender`, `pos.anular`, `caja.abrir`, `inventario.ajustar`. Se revisan en las rutas con el middleware `permission:` de Spatie (guard `api`) y en React con `PermissionRoute` y `CanAccess`. Los permisos existentes se conservan.
9. **Auditoría.** Un `AuditoriaService::registrar()` guarda quién, qué, cuándo, desde qué IP, y el antes y después en JSON, para las operaciones sensibles (anulaciones, ajustes, descuentos autorizados, cierres, cambios de precio y de permisos).
10. **Precisión.** Dinero en `decimal(12,2)`, cantidades y costos unitarios en `decimal(12,4)`.
11. **Frontend.** Se mantiene la estructura actual. `api.ts` gana un interceptor de Axios que pone el token y maneja el 401, sin romper las firmas existentes que reciben `token`. Las funciones nuevas van en `services/<modulo>.ts` y se reexportan. El POS es una pantalla completa fuera de `AppLayout` (`/pos`), pensada para el tacto: botones de al menos 48 px, teclado numérico en pantalla y diseño adaptable a tablet en horizontal y vertical.

---

## 3. Orden de implementación

El orden sigue las dependencias reales, no la numeración de la lista de requisitos. El POS necesita precios e inventario confiable y un turno de caja abierto. El modo offline necesita que el POS ya exista con `uuid`. Producción necesita conversión de unidades y costos.

| Fase | Módulo | Depende de |
|---|---|---|
| 0 | Fundamentos: seguridad, permisos, auditoría, configuración del negocio, build | — |
| 1 | Inventario (requisito 3) | 0 |
| 2 | Caja y turnos (2) | 0 |
| 3 | Punto de venta (1) | 1, 2 |
| 4 | Impresoras (8) | 3 |
| 5 | Funcionamiento offline (11) | 3, 4 |
| 6 | Clientes, cuentas por cobrar y pedidos especiales (6) | 3 |
| 7 | Compras, proveedores y cuentas por pagar (4) | 1 |
| 8 | Producción y recetas (5) | 1, 7 |
| 9 | Gastos (7) | 2, 7 |
| 10 | Reportes y exportación (9) | todas |
| 11 | Usuarios, permisos y auditoría: pantallas finales (10) | todas |

Cada fase sigue el mismo ciclo: revisar lo existente → migraciones y modelos → servicio → controlador y rutas con permisos → pruebas de feature en PHPUnit → hooks, servicios y páginas React → conexión → pruebas en navegador (escritorio y tablet) → corrección → documentación en `docs/modulos/<modulo>.md`.

---

## 4. Detalle por fase

### Fase 0 — Fundamentos

> **Terminada (2026-10-31).** Detalle de lo hecho en [modulos/fase-0-fundamentos.md](modulos/fase-0-fundamentos.md). Cambio respecto a este plan: `NumericKeypad`, `QtyStepper`, `PinModal` y `DateRangeFilter` pasan a las fases 2 y 3, donde se usan.

Backend:
- Mover `/productos/*` e `/inventario/*` dentro de `auth:sanctum` y agregar `permission:` a todas las rutas.
- Login: rechazar a los usuarios con `estado = 0`, limitar intentos (`throttle:5,1`), usar un mensaje genérico de credenciales inválidas (el frontend ya maneja el error) y registrar `ultimo_acceso`.
- Corregir las relaciones `usuario()` a `Administrador` y los `->name` a `->nombre`.
- `InventarioService`: agregar `lockForUpdate()`, validar el stock negativo según la configuración y aceptar `referencia_tipo`, `referencia_id` y `costo_unitario` como parámetros (para que ventas, compras y producción lo reutilicen).
- No devolver `$e->getMessage()` al cliente: guardarlo en el log y responder un mensaje genérico. Corregir los `commit` sin `begin`. Quitar `Proveedor::compras()` hasta la Fase 7.
- Migraciones nuevas:
  - `configuracion_negocio` (una fila): nombre, razón social, NIT, NRC, dirección, teléfono, moneda, `iva_porcentaje`, `precios_incluyen_iva`, `permitir_stock_negativo`, `descuento_max_sin_autorizacion`, mensaje del ticket y logo.
  - `auditorias`: `usuario_id`, `modulo`, `accion`, `entidad_tipo`, `entidad_id`, `datos_antes` (json), `datos_despues` (json), `ip`, `user_agent`, `created_at`.
  - `correlativos`: `tipo`, `prefijo`, `siguiente`.
  - `administradores` + `pin` (con hash, para autorizar descuentos y anulaciones desde el POS) + `ultimo_acceso`.
- Seeder de permisos por módulo y de los roles **Administrador**, **Cajero** y **Encargado de inventario** (se conservan `admin` y `editor`).
- Base de pruebas: `phpunit.xml` apuntando a la base `posdb_test` en MySQL (no SQLite, porque se usan `enum` y `ALTER` propios de MySQL), más pruebas de login y de acceso por permiso.

Frontend:
- Corregir los 12 errores de TypeScript para que `npm run build` pase.
- Interceptor de Axios para el token y el 401 (redirige al login y limpia la sesión).
- Recargar `/me` al iniciar para refrescar los permisos.
- Quitar la ruta duplicada y proteger `/admin/roles/:id/permisos`.
- Pantalla **Configuración del negocio**.
- Componentes reutilizables: `MoneyInput`, `QtyStepper`, `NumericKeypad`, `PinModal`, `StatusBadge`, `DateRangeFilter` y `PageHeader`.

### Fase 1 — Inventario

> **Terminada (2026-10-31).** Detalle en [modulos/fase-1-inventario.md](modulos/fase-1-inventario.md).

- Migración en `productos`: `precio_venta`, `clase` (`producto_terminado`, `materia_prima`, `reventa`, `empaque`), `se_vende`, `controla_stock`, `codigo_barras` (único, nulo), `permite_fraccion`, `orden_pos`, `color_pos`. `costo_unitario` pasa a `decimal(12,4)` y se mantiene como **costo promedio ponderado**.
- `unidades_medida`: `abreviatura`, `tipo` (`unidad`, `masa`, `volumen`), `factor_base` (g para masa, ml para volumen). Se siembran g, kg, lb, quintal, ml, l, unidad, docena.
- `movimientos_inventario`: ampliar el `enum` de `tipo` y agregar `costo_unitario`, `costo_total` y un índice `(producto_id, created_at)`.
- Endpoints: stock con filtros (clase, categoría, bajo mínimo), edición de stock mínimo, ajuste por conteo físico (a una cantidad exacta, incluido 0), **kardex** paginado por producto y rango de fechas con saldo y costo, historial de movimientos y alertas de stock bajo.
- Frontend: formulario de producto con precio, clase, unidad y stock mínimo; stock con indicadores; ajuste por conteo; kardex; aviso de stock bajo en el encabezado.

### Fase 2 — Caja y turnos

> **Terminada (2026-10-31).** Detalle en [modulos/fase-2-caja.md](modulos/fase-2-caja.md). Agregados: cierre ciego configurable y permiso `caja.reabrir`.

- Tablas:
  - `metodos_pago` (efectivo, tarjeta, transferencia, crédito y los que se agreguen; `afecta_efectivo`, `requiere_referencia`).
  - `cajas` (nombre, impresora predeterminada).
  - `turnos_caja` (caja, usuario de apertura y de cierre, fechas, `fondo_inicial`, `efectivo_esperado`, `efectivo_contado`, `diferencia`, totales por método, estado, observaciones).
  - `movimientos_caja` (`uuid`, turno, tipo `venta`/`ingreso`/`egreso`/`devolucion`/`abono`/`gasto`, método, monto, concepto, referencia, usuario).
  - `arqueos` (turno, conteo por denominación en JSON, total, tipo `parcial`/`cierre`).
- Reglas: un turno abierto por caja. No se vende sin turno. El cierre calcula lo esperado frente a lo contado y guarda la diferencia. Un turno cerrado no se reabre (solo un administrador, y queda en la auditoría).
- Frontend: apertura con fondo inicial, ingresos y egresos, arqueo con denominaciones (USD), cierre con resumen e impresión, historial de cierres y reporte por turno y por empleado.

### Fase 3 — Punto de venta

> **Terminada (2026-10-31).** Detalle en [modulos/fase-3-pos.md](modulos/fase-3-pos.md).

- Tablas:
  - `ventas` (`uuid` único, `numero`, turno, caja, usuario, cliente nulo, estado `completada`/`anulada`, subtotal, descuento, impuesto, total, `costo_total`, `origen` `online`/`offline`, `fecha_venta` del dispositivo, `sincronizada_at`, datos de anulación).
  - `ventas_detalle` (snapshot de nombre, precio y costo, cantidad, descuento por línea, `cantidad_devuelta`).
  - `ventas_pagos` (método, monto, recibido, cambio, referencia). Permite pagos combinados.
  - `devoluciones` y `devoluciones_detalle` (motivo, si reingresa al inventario o va a merma, forma de reembolso).
  - `pedidos` y `pedidos_detalle` (`uuid`, tipo `precuenta`/`especial`, nombre o referencia, estado `abierto`/`cobrado`/`cancelado`, anticipo, fecha de entrega). Las precuentas y los pedidos especiales comparten estructura y se convierten en venta al cobrar.
- `VentaService::registrar()`, en una sola transacción: valida el turno abierto, recalcula los totales, valida descuentos y pagos, bloquea y descuenta el stock (movimiento `venta`), registra los pagos y el movimiento de caja, asigna el correlativo y audita. `anular()` y `devolver()` revierten con sus propios movimientos.
- Descuentos: por línea y global, en porcentaje o monto. Por encima del máximo configurado se pide el PIN de alguien con `pos.descuento.autorizar`.
- Frontend `/pos`: categorías en columna, cuadrícula de productos con imagen y color, búsqueda instantánea y por código de barras, carrito con `QtyStepper`, cantidades fraccionadas, modal de cobro con teclado numérico, botones de billetes rápidos y pagos combinados, precuentas en espera y retomables, historial de ventas del turno con reimpresión, anulación con PIN y devolución.

### Fase 4 — Impresoras

> **Terminada (2026-11-01).** Detalle en [modulos/fase-4-impresoras.md](modulos/fase-4-impresoras.md). Cambio respecto a este plan: en lugar de `mike42/escpos-php` se escribió un generador ESC/POS propio (sin dependencias nuevas).

- Migración en `impresoras`: `conexion` (`red`, `windows`), `puerto` (9100), `nombre_windows`, `ancho_caracteres` (32 o 48), `uso` (`ticket`, `comanda`, `ambos`), `predeterminada`. Se conservaba `impresoras_categorias` para enrutar comandas por categoría; se quitó el 2026-11-02, ahora las comandas van por área de preparación.
- `trabajos_impresion` (tipo, referencia, impresora, estado `pendiente`/`impreso`/`error`, intentos, error, contenido) para reintentar e informar los fallos.
- `ImpresionService` con `mike42/escpos-php`: por red (TCP 9100, usando `ip_impresora`) o por el spooler de Windows (conservando el método actual), apertura del cajón (pin 2 o 5), corte y logo. Plantillas de ticket, comanda, cierre de caja y prueba con los datos de `configuracion_negocio`.
- Respaldo: impresión desde el navegador (CSS de 80 mm o 58 mm). Es la que se usa cuando el servidor no responde (Fase 5).
- Frontend: selección de impresora por caja, botón de prueba con el error visible y panel de trabajos fallidos con reintento.

### Fase 5 — Funcionamiento offline

> **Terminada (2026-11-01).** Detalle en [modulos/fase-5-offline.md](modulos/fase-5-offline.md). Cambio respecto a este plan: IndexedDB y service worker escritos a mano, sin `dexie` ni `vite-plugin-pwa`.

- App instalable (PWA con `vite-plugin-pwa` y Workbox) para que el POS cargue sin conexión.
- IndexedDB (`dexie`): caché de productos, precios, categorías, métodos de pago, configuración y turno abierto; cola de ventas, pagos y movimientos de caja pendientes.
- Cada venta offline se guarda localmente con su `uuid`, se imprime en el navegador con un número provisional (`OFF-<caja>-<seq>`) y entra a la cola.
- `POST /pos/sync`: recibe lotes y procesa cada elemento en su propia transacción. Es idempotente por `uuid` y devuelve el resultado por elemento (`creada`, `ya_existia`, `conflicto` con su motivo).
- Conflictos:
  - Precio cambiado: se respeta el precio cobrado y se marca la venta.
  - Stock insuficiente: se acepta (la venta ya ocurrió), se registra y se alerta.
  - Producto desactivado: se usa el snapshot.
  - Turno cerrado durante la desconexión: la venta se asigna a su turno y se marca como sincronizada tarde.
- Indicador en el encabezado (en línea, sin conexión, N pendientes, error) y pantalla con el detalle de la cola. Se bloquea el cierre de turno mientras el dispositivo tenga ventas sin sincronizar.

### Fase 6 — Clientes

> **Terminada (2026-11-01).** Detalle en [modulos/fase-6-clientes.md](modulos/fase-6-clientes.md). Cambios respecto a este plan: los abonos se aplican a varias cuentas (`abonos_detalle`), el anticipo de pedidos entra a caja con su propio tipo (`anticipo`) y exceder el límite de crédito se autoriza con PIN.

- `clientes` (nombre, teléfono, correo, DUI/NIT, dirección, `limite_credito`, `saldo`, activo).
- `cuentas_cobrar` (venta, cliente, monto, saldo, vencimiento, estado) y `abonos` (`uuid`, cuenta, monto, método, turno, usuario). El abono en efectivo entra a la caja.
- Venta a crédito desde el POS (método `credito`, respetando el límite), historial de compras, estado de cuenta en PDF y pedidos especiales (pasteles por encargo) con anticipo y fecha de entrega.

### Fase 7 — Compras y proveedores

> **Terminada (2026-11-01).** Detalle en [modulos/fase-7-compras.md](modulos/fase-7-compras.md). Cambios respecto a este plan: las compras se registran en la unidad del proveedor (quintal, docena) y se convierten a la del inventario; el pago puede salir de la caja o de fuera de caja (banco); la pantalla vieja de proveedores en Configuración se reemplazó por la de Compras.

- `proveedores` + NIT, teléfono, correo, dirección, contacto, `dias_credito`.
- `ordenes_compra` y su detalle (borrador, enviada, recibida parcial, recibida, cancelada).
- `compras` (recepción) y su detalle, con costo de adquisición, contado o crédito. Genera la entrada de inventario (`compra`) y recalcula el costo promedio.
- `cuentas_pagar` y `pagos_proveedor`. El pago desde caja genera un egreso.
- Historial de compras por proveedor y producto.

### Fase 8 — Producción y recetas

> **Terminada (2026-11-01).** Detalle en [modulos/fase-8-produccion.md](modulos/fase-8-produccion.md). Cambios respecto a este plan: editar una receta ya usada crea su versión siguiente; la producción admite insumos fuera de receta; el resumen se ve en pantalla (la exportación a Excel/PDF llega con Reportes).

- `recetas` (producto terminado, rendimiento, versión, activa) y `recetas_ingredientes` (insumo, cantidad, unidad, % de merma). La conversión de unidades usa `factor_base`.
- Costo de receta calculado con el costo promedio vigente de los insumos.
- `producciones` (fecha, receta, cantidad planificada y producida, costo total y unitario, usuario) y `producciones_consumos` (consumo teórico frente a real). En una sola transacción descuenta las materias primas (`consumo_produccion`), aumenta el producto terminado (`produccion`) y actualiza su costo.
- `mermas` (producto, cantidad, motivo `vencido`/`quemado`/`dañado`/`otro`, costo) con su movimiento `merma`. El pan del día que no se vendió se registra rápido desde una pantalla de cierre del día.
- Reporte de producción diaria, rendimiento real frente al teórico y mermas.

### Fase 9 — Gastos

> **Terminada (2026-11-01).** Detalle en [modulos/fase-9-gastos.md](modulos/fase-9-gastos.md). Cambios respecto a este plan: el gasto también puede pagarse fuera de caja (banco, tarjeta) sin tocar la gaveta; anularlo requiere autorización.

- `categorias_gasto` y `gastos` (fecha, categoría, proveedor opcional, descripción, monto, método de pago, turno si se paga de caja, comprobante adjunto, usuario).
- El gasto pagado en efectivo desde la caja genera un `movimiento_caja` de egreso.
- Reporte por categoría y por período.

### Fase 10 — Reportes

> **Terminada (2026-11-01).** Detalle en [modulos/fase-10-reportes.md](modulos/fase-10-reportes.md). Cambios respecto a este plan: 15 reportes con una sola forma tabular (pantalla, Excel y PDF usan el mismo resultado); se agregaron ventas por hora, utilidad estimada (resultado del periodo) y cartera de clientes y proveedores; dompdf ya se había instalado en la Fase 6.

- `ReportesService` con consultas agregadas e índices por fecha. Endpoints con filtro de fechas: ventas diarias y por rango, por empleado, por método de pago, productos más vendidos, utilidad estimada (venta − costo snapshot), compras, gastos, inventario valorizado, kardex, producción, mermas y cierres de caja.
- Exportación a Excel con `maatwebsite/excel` (ya instalado) y a PDF con `barryvdh/laravel-dompdf` (dependencia nueva).
- Panel de inicio con indicadores del día y gráficas (ApexCharts, ya instalado).

### Fase 11 — Usuarios, permisos y auditoría

> **Terminada (2026-11-01).** Detalle en [modulos/fase-11-seguridad.md](modulos/fase-11-seguridad.md). La asignación de PIN y la desactivación de usuarios ya existían desde la Fase 0; esta fase agregó la matriz de permisos, el visor de auditoría y una prueba automática que revisa el permiso de cada ruta.

- Matriz de permisos por módulo en la pantalla de roles existente, asignación de PIN y desactivación de usuarios.
- Visor de auditoría con filtros por usuario, módulo, acción y fechas, y el detalle del antes y después.
- Revisión final de que cada ruta tenga su permiso y cada pantalla su `PermissionRoute`.

---

## 5. Pruebas

- Backend: pruebas de feature en PHPUnit por módulo sobre `posdb_test`. Como mínimo: permisos (403), totales recalculados, stock descontado y revertido, idempotencia por `uuid`, cierre de caja con diferencia y consumo de receta con conversión de unidades.
- Frontend: `tsc -b` y `eslint` limpios, `vitest` para la lógica pura (carrito, totales, cola offline) y pruebas manuales en navegador a tamaño de escritorio y de tablet.

---

## 6. Preguntas abiertas para el negocio

1. **Dónde corre el servidor.** *(Resuelto 2026-11-06: VPS de Hostinger; la tablet imprime. Ver [DESPLIEGUE.md](DESPLIEGUE.md).)* Hoy la API apunta a `192.168.1.4:8080` (Laragon en la red local). Si el servidor está dentro de la panadería, perder internet no detiene el POS; el modo offline cubre la caída del servidor o del Wi-Fi. Si se va a alojar en la nube, el offline pasa a ser crítico. El diseño sirve para ambos casos, pero cambia las prioridades.
2. **Facturación electrónica (DTE del Ministerio de Hacienda de El Salvador).** ¿Se necesita emitir DTE o basta con el ticket interno? Si se necesita, conviene agregar un módulo aparte antes de salir a producción.
3. **IVA.** ¿Los precios de venta ya incluyen el 13 %? (Propuesta: sí, y el ticket desglosa el impuesto.)
4. **Stock negativo.** ¿Se permite vender pan que todavía no se registró como producido? (Propuesta: permitir con alerta, configurable.)
5. **Número de cajas** y si cada cajero usa su propio usuario o se comparte una tablet con cambio rápido por PIN. *(Resuelto 2026-11-08: una sola caja física, «Caja principal»; cada mesero entra con su usuario y abre su turno. Si alguien se va sin cerrar, el siguiente cierra ese turno con arqueo y abre el suyo. El sistema admite varias cajas si un día hay otra gaveta con su propio dispositivo.)*

---

## 7. Estado al cierre del plan (2026-11-01)

Las 12 fases (0 a 11) están terminadas y documentadas en `docs/modulos/`. El backend tiene 183 pruebas de feature (1026 aserciones) que pasan sobre `posdb_test`, y el frontend compila con `tsc -b` y `eslint` sin errores.

Pendiente fuera del alcance de las fases:

- Las preguntas abiertas de la sección 6 (servidor, DTE, número de cajas). Del IVA y el stock negativo quedó la propuesta configurable.
- `vitest` para la lógica pura del frontend (carrito, totales, cola offline): no se agregó; esa lógica se cubrió con las pruebas del backend, que recalculan los mismos importes, y con pruebas manuales en el navegador.
- Verificaciones que requieren el entorno real: impresión USB por Windows, service worker en el build de producción y uso en tableta.

### Fase 12 — Mesas, cuentas separadas y comandas (agregada después del plan)

> **Terminada (2026-11-01).** Detalle en [modulos/fase-12-mesas.md](modulos/fase-12-mesas.md). Punto de venta por terminales al estilo de KOLO: mapa de mesas, modificadores con precio, cuentas separadas, comandas por área con pantalla de cocina e impresión, y cobro por cuenta o por productos. Con esta fase el backend tiene 194 pruebas.
>
> **Cambio (2026-11-02):** se quitó la pantalla de cocina a pedido del cliente. Las comandas salen en un ticket impreso por área (cocina, barra), y desde la mesa se reimprimen o se marcan entregadas.

### Fase 13 — Recetas de venta, servicios y lista de compras del turno (pedida por el cliente, 2026-11-01)

> **Terminada (2026-11-02).** Detalle en [modulos/fase-13-recetas-venta.md](modulos/fase-13-recetas-venta.md). Cambios respecto a lo propuesto abajo: el uso de la receta no se elige, lo decide el producto (sin inventario propio → se descuenta al vender); la autorización de bajas aplica solo a insumos de recetas activas, con el permiso nuevo `inventario.autorizar_baja`. Con esta fase el backend tiene 206 pruebas.
>
> Lo que cubría el sistema y lo que faltaba (sale de los audios del cliente):

| Pedido del cliente | Estado actual |
|---|---|
| Ingreso de producto terminado y de materia prima | Ya existe: `clase` del producto, compras y entradas de inventario |
| Quién registró cada ingreso | Ya existe: `usuario_id` en compras, entradas y cada movimiento del kardex |
| Unidades ml, onza, galón, libra | Ya existen con conversión (`factor_base`). Falta sembrar **Porción** (tipo `unidad`) |
| 4 lb de frijol crudo → 80 porciones de frijol cocido | Ya existe: receta de **producción** con rendimiento 20 porciones por libra |
| Vender una limonada descuenta 4 limones | **Falta.** Hoy la venta solo descuenta el producto vendido; las recetas solo se usan al producir |
| Servicio (cantar las mañanitas, $3.00) que gasta pastel, gorro, etc. | **Falta.** Mismo mecanismo que el anterior |
| Platos a $0.00 (cena típica del personal) que solo bajan inventario | Casi: la venta en $0.00 ya se acepta sin pago. Falta marcarla como **consumo interno** para separarla en reportes |
| El aceite solo baja por los platos, no a mano | Parcial: el ajuste por conteo existe y se audita. Falta exigir PIN y reportarlo como faltante |
| Al cerrar turno, lista de lo que falta (bajo mínimo) para comprar | **Falta.** Hoy solo está el aviso de stock bajo en el encabezado |

Diseño propuesto:

- **Receta de venta** (`recetas.uso` = `produccion` | `venta`). Un producto que se vende con receta de venta no lleva existencia propia (`controla_stock = 0`): al venderlo, `VentaService` descuenta cada ingrediente por `InventarioService` con el movimiento `venta` y `referencia_tipo = venta`. Anular o devolver la venta los regresa. El costo de la venta es la suma de los ingredientes. Sirve igual para bienes (limonada, crema con Nutella) y para servicios (mañanitas con pastel y gorro). Los modificadores de la Fase 12 pueden sumar ingredientes extra.
- **Consumo interno.** Bandera `consumo_interno` en el producto (o motivo en la venta): total $0.00, sin pago ni movimiento de caja, descuenta la receta y en reportes aparece aparte como consumo al costo (uso para venta frente a consumo del personal).
- **Ajustes manuales a la baja** solo con permiso `inventario.ajustar` y PIN; la diferencia queda como faltante en el kardex y en el reporte del turno.
- **Lista de compras del turno.** Al cerrar turno el resumen muestra e imprime: ventas, efectivo, quién tenía la caja y de qué hora a qué hora, y los insumos **bajo su mínimo** (solo los que bajaron del mínimo; si están en el máximo o arriba no salen). Al abrir el siguiente turno se le muestra la misma lista para que haga la compra. Opcional: sugerir el mínimo según el consumo promedio por recetas de los últimos días.
