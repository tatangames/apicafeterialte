# Guía de puesta en marcha — Panadería Eduardo

La base está **en cero** desde el 2026-11-06, solo con los registros base. Esta guía va en el orden en que conviene cargar las cosas: cada paso usa lo que se cargó en el anterior. Al final se explica el trabajo de cada día y qué significa cada reporte.

Los menús son los de la **web**. La tablet tiene lo mismo en sus botones de Inicio: Mesas, Venta rápida, Caja y turno, Ventas, Clientes, Pedidos especiales, Compras, Gastos, Inventario, Producción, Catálogo, Ajustes y Resumen del día.

## Lo que ya viene cargado

| Qué | Contenido |
|---|---|
| Usuarios | **Administrador** (tatangamess@gmail.com), **Mesero 1** y **Mesero 2** (mesero1@ / mesero2@panaderia.test). Tienen una contraseña inicial de prueba que hay que cambiar en el paso 1 |
| Roles | `admin` (todo) y `mesero` (atiende, caja, compras, gastos, inventario, producción; no anula, no devuelve, no ve reportes) |
| Caja | «Caja principal» |
| Formas de pago | Efectivo (abre el cajón), Tarjeta, Transferencia (pide referencia) y Crédito |
| Terminales | CAJA 1 (mostrador) y MESA 1 a MESA 6 |
| Áreas de preparación | Cocina y Barra |
| Unidades de medida | Unidad, Docena, Gramo, Kilogramo, Onza, Libra, Arroba, Quintal, Mililitro, Litro, Galón y Porción, con su conversión |
| Categorías de gasto | Alquiler, Gas, Impuestos y trámites, Limpieza, Mantenimiento, Otros, Papelería, Planilla, Servicios básicos, Transporte |
| Datos del negocio | Nombre «Panadería Eduardo», USD, descuento máximo sin autorización 10 %, **no se permite vender sin existencia** |

No hay categorías de productos, productos, recetas, proveedores, clientes ni impresoras. Eso se carga con esta guía.

El respaldo de lo que había antes está en `storage/app/respaldos/antes_de_borrar_todo_2026-11-06_1601.sql.gz`.

---

## Parte 1 — Configuración (una sola vez)

### Paso 1. Entrar y asegurar las cuentas

1. Entra a la web con el correo del administrador.
2. **Usuarios y seguridad → Mi cuenta y PIN:**
   - Cambia la contraseña.
   - Pon tu **PIN** de 4 a 6 dígitos. Con ese PIN autorizas en la tablet de un mesero lo que él no puede hacer: anular, cancelar un producto ya enviado, dar descuentos grandes, abrir el cajón sin venta, devoluciones.
3. **Usuarios y seguridad → Usuarios:**
   - Cambia el nombre, correo y contraseña de Mesero 1 y Mesero 2 por los empleados reales, o crea los que falten con su rol.
   - Cada empleado usa **su propio usuario**: así cada venta y cada turno quedan a su nombre.
4. **Usuarios y seguridad → Roles y permisos:** revisa qué puede hacer el rol mesero. Cada permiso tiene un botón **«?»** que explica qué hace.

### Paso 2. Datos del negocio

**Configuración → Datos del negocio.** Lo que se llena aquí sale en el ticket:

- **Ticket:** razón social, nombre comercial, NIT, NRC, actividad económica, dirección, teléfono, logo y mensaje del ticket.
- **Permitir stock negativo:** apagado, no se puede vender lo que no hay en existencia (ni platos cuyos ingredientes no alcanzan). Encendido, se vende aunque el sistema diga 0.
- **Descuento máximo sin autorización:** encima de ese porcentaje se pide el PIN del administrador.
- **Cierre ciego:** si está encendido, el mesero cuenta el efectivo al cerrar sin ver cuánto debería haber.

### Paso 3. Caja y formas de pago

**Configuración → Cajas y formas de pago.**

- **Caja principal:** revísala y, después del paso 4, asígnale su impresora.
- **Formas de pago:**
  - **«Mueve la gaveta»:** solo la deben tener las formas de pago en efectivo. Con ellas se abre el cajón al cobrar y el dinero cuenta en el efectivo esperado del cierre.
  - **«Pide referencia»:** para transferencias (número de comprobante).
  - **Crédito:** solo aparece al cobrar si la venta tiene un cliente con límite de crédito.

### Paso 4. Impresora

**Configuración → Impresoras → + Nueva impresora.** Para la Star del mostrador:

| Campo | Valor |
|---|---|
| Nombre | Mostrador |
| Conexión | **Desde la tablet (Wi-Fi)** si el servidor está en la nube; **Desde el servidor (red local)** si el servidor está en el local |
| IP y puerto | 192.168.1.69 y 9100 |
| Modelo / tipo | **Star TSP100 (imagen)** |
| Papel | 80 mm |
| Uso | Ambos (tickets y comandas) |
| Cajón de dinero | Pin 2 |
| Predeterminada | Sí |

- **Probar:** toca **Imprimir prueba** y **Prueba producción**.
- **Con «Desde la tablet»:** no hay que activar nada: todas las tablets con la app abierta y sesión iniciada imprimen.
- **Cuándo se abre el cajón:** solo al cobrar con efectivo, o con **Abrir cajón** en Caja.

### Paso 5. Mesas, áreas y cocina

**Configuración → Mesas y cocina.**

- **Terminales:**
  - Ya están CAJA 1 y MESA 1 a 6. Agrega, renombra u ordena según el local; la zona (Salón, Terraza…) sirve para filtrar el mapa.
  - CAJA 1 es la del mostrador: ahí quedan los pedidos de mostrador que se cobran cuando el cliente recoge.
- **Áreas de preparación:**
  - A Cocina y Barra asígnales su impresora (puede ser la misma Mostrador). La comanda sale con «** COCINA **» o «** BARRA **» arriba.
  - Aquí también se elige qué **categorías** van a cada área; eso se hace después del paso 6.
- **Modificadores (extras):** se cargan en el paso 12, cuando ya existan los productos e insumos.

### Paso 6. Categorías de productos

**Configuración → Categorias → nueva.** Agrupan los productos en las pestañas del punto de venta y deciden a qué área va la comanda. Por ejemplo:

| Categoría | Área de su comanda |
|---|---|
| Pan | Sin comanda: se entrega directo |
| Postres (de vitrina: tres leches, flan) | Sin comanda |
| Postres preparados (waffles, crepas) | Cocina |
| Bebidas calientes con café | Barra |
| Bebidas frías sin café | Barra |
| Bebidas embotelladas (reventa) | Sin comanda |
| Desayunos | Cocina |
| Comida | Cocina |
| Insumos (harina, azúcar, leche…) | Sin comanda (no se venden) |

Después vuelve a **Mesas y cocina → Áreas de preparación** y asigna las categorías a Cocina y a Barra. Las que se entregan directo quedan en **«Sin comanda»**: al venderlas solo sale el ticket de venta, y en una mesa quedan entregadas al tocar Enviar.

### Paso 7. Unidades de medida (revisar)

**Configuración → Unidad de Medida.** Ya vienen con su conversión: 1 lb = 453.59 g, 1 galón = 3,785.41 ml, 1 quintal = 100 lb. Agrega solo las que falten, por ejemplo «Bolsa» o «Caja» de tipo unidad. La conversión permite comprar el aceite por galón y gastarlo en ml en las recetas.

### Paso 8. Proveedores

**Compras → Proveedores → nuevo:** nombre, NIT, NRC, contacto, teléfono y **días de crédito**. Con 0 días, las compras son al contado; con más, la compra queda en Cuentas por pagar con su fecha de vencimiento.

### Paso 9. Productos

**Inventario → Catálogo de Productos → nuevo.** Lo más importante es la **clase** y si **lleva inventario**:

| Tipo | Clase | ¿Se vende? | ¿Lleva inventario? | Ejemplos |
|---|---|---|---|---|
| Lo que se hornea y se vende por pieza | Producto terminado | Sí | **Sí** (sube con la producción, baja al vender) | Pan francés, Tres leches |
| Lo que se compra hecho y se revende | Reventa | Sí | **Sí** (sube con la compra) | Coca-Cola lata, Pepsi 500 ml |
| Lo que se prepara al momento | Producto terminado | Sí | **No**: se descuentan los ingredientes de su receta | Americano, Limonada, Huevos divorciados |
| Ingredientes | Materia prima | No | **Sí**, con decimales | Harina (lb), Azúcar (g), Leche (ml), Café molido (g), Huevo (u), Limón (u) |
| Bolsas, vasos, cajas | Empaque | No | Sí | Vaso desechable, Caja de pastel |
| Comida del personal | Producto terminado con **Consumo interno** | Sí, a $0.00 | No | Cena del personal |

En cada producto llena:

- **Datos básicos:** nombre, categoría, unidad (la de inventario, por ejemplo Gramo para el azúcar), y si «Permite decimales» para lo que se pesa o mide.
- **Precio de venta y costo inicial.** El costo se actualiza solo con cada compra o producción (costo promedio). Para cambiar precios después se necesita el permiso «Cambiar precios y costos», y el cambio queda en Auditoría.
- **Stock mínimo:** cuando la existencia llega a ese número, el producto sale en la campana y en la **lista de compras del cierre de turno**. Con 0 nunca avisa.
- **Para el punto de venta:** foto y color de la tarjeta, y código de barras si tiene.

Después, en **Inventario → Ordenar POS**, ordena cómo aparecen las tarjetas en el punto de venta.

### Paso 10. Recetas

**Producción → Recetas → nueva.** Hay dos tipos y el sistema elige cuál según el producto:

- **Receta de venta** (el producto **no** lleva inventario): se descuenta **al vender**. Ejemplo: Limonada → 4 Limón + 30 g de Azúcar. Al vender 3 limonadas bajan 12 limones y 90 g de azúcar, y el costo de la venta es el de esos ingredientes. Sirve también para servicios (Cantar las mañanitas → 1 pastel pequeño + 1 gorro) y para el consumo interno.
- **Receta de producción** (el producto **sí** lleva inventario): se usa al registrar lo horneado. Ejemplo: Pan francés, rendimiento 100 → 10 lb de Harina, 200 g de Levadura… Al registrar «producí 300» sube el pan y bajan los ingredientes de 3 tandas. También sirve para preparar a granel: 4 lb de frijol rinden 80 porciones.

Cada ingrediente se escribe en la unidad que se usa en la cocina (g, ml, onza, taza), y el sistema convierte. Hay un campo de **merma %** opcional, por lo que se desperdicia al preparar.

Si el negocio no permite stock negativo, un plato cuyos ingredientes no alcanzan sale **«Agotado»** y no se deja vender.

### Paso 11. Clientes

**Clientes → Clientes → nuevo:** nombre, documento, teléfono y dirección.

- **Límite y días de crédito:** solo para quien compra fiado. Sin límite, no puede pagar con Crédito.
- **Para qué sirve:** el cliente se elige en la venta o en la mesa. Sale en el ticket, permite vender a crédito y guarda su historial.

### Paso 12. Modificadores (extras)

**Configuración → Mesas y cocina → Modificadores → nuevo grupo:**

- **El grupo:** nombre (Leche, Endulzante, Término de la carne), si es **obligatorio**, si se puede elegir **más de una** opción y a qué productos aplica.
- **Cada opción:** nombre, precio extra (puede ser $0.00) y, si gasta algo, el **insumo, la cantidad y la unidad**. Por ejemplo, «Con leche» +$0.25 gasta 150 ml de Leche. Al vender se descuenta, y si no hay leche la opción sale «Agotado».

### Paso 13. Inventario inicial

Hay tres formas de cargar lo que hay hoy:

- **Recomendado: compras.** **Compras → Compras → nueva**, con proveedor, documento, productos, cantidad y costo. Sube la existencia, fija el costo y, si fue a crédito, crea la cuenta por pagar.
- **Lo que ya está en bodega:** **Inventario → Inventarios → + Nuevo registro**, tipo **Entrada**, con cantidad y costo unitario.
- **Conteo físico:** **Inventario → Inventarios → Conteo físico**. Se anota lo que se contó y el sistema ajusta solo las diferencias, como faltante o sobrante valorado.

Al terminar revisa **Inventario → Inventarios**: cada producto con su existencia, costo y valor.

Con esto el sistema está listo para vender.

---

## Parte 2 — El día a día

### 1. Abrir el turno

Cada empleado entra con su usuario.

- **Abrir:** en **Caja → Turno actual** (tablet: **Caja y turno**) pone el **fondo inicial** de la gaveta.
- **Lista de compras:** al abrir se ve la lista de lo que dejó el turno anterior.
- **Un turno por persona:** cada quien vende en su propio turno. Si el anterior no cerró, el siguiente puede cerrarlo a su nombre y abrir el suyo.

### 2. Registrar lo horneado

**Producción → Producción del día:** elige la receta y escribe cuánto salió. Sube el producto terminado y bajan las materias primas.

### 3. Vender

- **Venta rápida (mostrador):**
  - Toca los productos, o búscalos o escanéalos, y luego **Cobrar**.
  - Se elige la forma de pago (efectivo con lo recibido, tarjeta, transferencia o **pago mixto**).
  - Sale el ticket, se abre el cajón si hubo efectivo y queda la ventana del **cambio** hasta tocar «Listo».
- **Pedido de mostrador (se cobra al recoger):** en Venta rápida, **Enviar comanda** con el nombre del cliente («Juan»). La comanda sale a cocina o barra, y el pedido queda en **Por cobrar** hasta que recoge.
- **Mesas:**
  1. Toca una mesa libre y luego **Abrir mesa**.
  2. Agrega productos, con sus extras y notas.
  3. **Enviar** manda una comanda por área.
  4. Se pueden hacer **cuentas separadas** (pasar productos de una cuenta a otra), imprimir la **precuenta**, cobrar solo algunos productos o **cobrar toda la mesa**.
  5. Al cobrar todo, la mesa queda libre.
- **Descuentos:** por producto o a toda la venta. Encima del máximo se pide el PIN.
- **Cancelar un producto ya enviado:** pide motivo y PIN, y a la cocina le sale un aviso **CANCELADO**.
- **Consumo interno** (cena del personal): se agrega a $0.00, no se cobra y solo baja los ingredientes.
- **Venta a crédito:** elige el cliente (con límite de crédito) y cobra con Crédito; el ticket sale para la firma. Después, el cliente abona en **Clientes → Cuentas por cobrar**.

### 4. Pedidos especiales (encargos)

**Clientes → Pedidos especiales:** cliente, productos, fecha de entrega y **anticipo** (entra a la caja del día). Al entregarlo se cobra solo lo que falta. El panel avisa los pedidos del día y los atrasados.

### 5. Durante el turno

- **Gastos** (gas, compra menor): **Gastos → nuevo**, con categoría, monto y si salió de la caja (baja el efectivo esperado) o de otra cuenta.
- **Ingresos y egresos de efectivo** que no son ventas: **Caja → Turno actual → + Ingreso / − Egreso**.
- **Abrir el cajón sin venta:** **Abrir cajón**, con motivo. Sin permiso se pide el PIN y queda en Auditoría.
- **Compras que llegan:** **Compras → Compras → nueva**. Para pagar a un proveedor, **Cuentas por pagar → pago**.
- **Anular una venta o hacer una devolución:** **Ventas → Historial de ventas**, se abre el ticket. Pide motivo, y PIN si el empleado no tiene el permiso.
- **Mermas** (pan quemado, vencido): **Producción → Mermas y cierre del día**, con motivo.
- **Arqueo o Corte X:** contar a media jornada sin cerrar.

### 6. Cerrar el turno

**Caja → Turno actual → Cerrar turno** (tablet: **Caja y turno → Cerrar turno**).

1. **Avisos:** si quedan mesas o pedidos de mostrador sin cobrar, el sistema avisa.
2. **Conteo:** se cuenta el efectivo por billetes y monedas, o el total.
3. **Resultado:** el sistema calcula el **faltante o sobrante** contra el efectivo esperado: fondo + ventas en efectivo + ingresos − egresos − gastos de caja − devoluciones en efectivo.
4. **Impresión:** sale el cierre con las ventas por forma de pago y por empleado, el consumo del turno y la **lista de compras** (insumos bajo su mínimo).
5. **Sesión:** al cerrar se cierra la sesión.

### 7. Revisar

- **Dashboard:** cómo va el día y el mes.
- **Inventario → Inventarios:** qué está bajo el mínimo. La campana avisa al llegar al mínimo.
- **Usuarios y seguridad → Auditoría:** quién anuló, autorizó, abrió el cajón o cambió precios.

---

## Parte 3 — Los reportes y qué significa cada uno

**Reportes:** se elige el reporte y el rango de fechas (máximo un año). Cada uno trae indicadores arriba, gráfica cuando aplica, tabla con totales y botones para **Excel** y **PDF**. Hace falta el permiso «Ver reportes» (y «Exportar» para descargar). Las ventas **anuladas no cuentan** en ningún reporte de ventas.

### Ventas

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Ventas por día** | ¿Cuánto vendí cada día y cuánto gané? | Columnas por día: **Tickets** (ventas cobradas, sin contar las de puro consumo interno), **Ventas** (lo cobrado), **Descuentos**, **Devoluciones** (restan el día que se hacen), **Venta neta** (ventas − devoluciones), **Costo** (lo que costó lo vendido), **Utilidad** (venta neta − costo), **Margen** (utilidad ÷ venta neta) y **Consumo interno (costo)** (lo que comió el personal, aparte) |
| **Ventas por hora** | ¿A qué hora se vende más? | Tickets y total por franja de hora. Sirve para saber cuándo hornear y cuánto personal poner |
| **Ventas por empleado** | ¿Quién vendió cuánto? | Tickets, total, ticket promedio, descuentos que dio y ventas anuladas de cada empleado |
| **Quién agregó cada producto** | ¿Qué mesero pidió cada cosa? | Una línea por producto: fecha y hora, **quién lo agregó** (aunque lo cobre otro), dónde (mesa, pedido de mostrador o venta rápida), cuenta, cantidad, importe y estado (cobrado, enviado, cancelado con quién lo canceló y por qué). Arriba, cuánto agregó cada empleado. Filtro por empleado. En «Ventas por empleado» la venta es de quien **cobró**; aquí cada producto es de quien lo **pidió** |
| **Ventas por forma de pago** | ¿Cuánto entró en efectivo, tarjeta, transferencia o crédito? | Por método: lo **cobrado**, lo **devuelto** y el **neto**, con su %. Sirve para cuadrar con el banco o el POS de tarjeta |
| **Productos más vendidos** | ¿Qué se vende más y qué deja más? | Por producto: cantidad (menos devoluciones), ventas, costo, **utilidad y margen**. Filtro por categoría. Muestra qué productos dejan poco margen. No incluye el consumo interno |
| **Utilidad estimada** | ¿Ganó o perdió el negocio en el periodo? | Por día: ventas, costo de ventas, **utilidad bruta**, menos **mermas**, **consumo interno** y **gastos** = **Resultado**. Las compras no restan aquí porque son inventario: su costo cuenta cuando se vende, se produce o se merma |

### Caja

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Cierres de caja** | ¿Cuadraron los turnos? | Cada turno cerrado: quién abrió y cerró, horas, fondo, ventas, ingresos, egresos, **efectivo esperado**, **contado** y **diferencia** (negativa = faltante). El indicador cuenta los turnos con faltante |

### Compras y gastos

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Libro de compras** | ¿Qué le compré a quién? | Cada compra con proveedor, NIT/NRC, documento, subtotal, IVA de la factura del proveedor, total y lo que falta pagar. Filtro por proveedor. Sirve de apoyo para la contabilidad |
| **Gastos** | ¿En qué se fue el dinero? | Cada gasto con fecha, categoría, descripción, origen (caja u otra cuenta) y monto, con el total del periodo. Filtro por categoría para ver, por ejemplo, solo el gas |

### Inventario

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Inventario valorizado** | ¿Cuánto vale lo que tengo hoy? | Por producto: existencia, costo promedio, **valor al costo** (lo invertido) y **valor a precio de venta** (lo que se recibiría al venderlo). Filtro por clase. Es una foto de hoy, sin fechas |
| **Kárdex de un producto** | ¿Por qué tengo esta existencia? | Se elige un producto. Muestra cada movimiento (compra, venta, producción, merma, ajuste, consumo interno) con fecha, quién lo hizo, entrada, salida, **saldo** y costo, del saldo inicial al final. Sirve para encontrar faltantes |

### Producción

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Producción** | ¿Cuánto horneé y cuánto me costó? | Cada producción: receta, lo planificado, lo **producido**, el **rendimiento** (si salió lo que la receta promete) y el costo de los ingredientes |
| **Mermas** | ¿Qué se perdió y por qué? | Cada merma con producto, cantidad, **motivo** (quemado, vencido, dañado) y **costo**. Filtro por motivo. Lo que más se pierde indica qué hornear menos |

### Cartera

| Reporte | Qué responde | Cómo leerlo |
|---|---|---|
| **Cuentas por cobrar** | ¿Quién me debe? | Clientes con saldo de crédito: total, **vencido** y por vencer. Foto de hoy |
| **Cuentas por pagar** | ¿A quién le debo? | Proveedores con saldo: total, **vencido** y próximo vencimiento. Foto de hoy |

### El panel de inicio (Dashboard)

- **Arriba:** periodo (hoy, ayer, esta semana, este mes…), total de ventas, gastos y **utilidad bruta**.
- **Listas:** los últimos 10 pedidos y los 10 productos más vendidos.
- **Debajo:** ventas por hora del día, alertas (productos bajo el mínimo, pedidos especiales de hoy y atrasados, turnos abiertos), los últimos 30 días y el resumen del mes, con lo que se debe cobrar y pagar.
- **Sin «Ver reportes»:** el empleado ve solo las ventas y el ticket promedio, sin utilidad ni costos.
- **Actualización:** se refresca solo cada 2 minutos.

---

## Orden resumido

1. Cuentas y PIN.
2. Datos del negocio.
3. Caja y formas de pago.
4. Impresora.
5. Mesas y áreas.
6. Categorías.
7. Unidades.
8. Proveedores.
9. Productos.
10. Recetas.
11. Clientes.
12. Extras.
13. Inventario inicial.
14. Día a día: abrir turno → producir → vender → gastos y compras → mermas → cerrar turno.
15. Reportes.

Las guías detalladas de cada pantalla también están en el menú **Manuales** de la web y la tablet.
