<?php

/*
|--------------------------------------------------------------------------
| Catálogo de permisos del sistema (guard "api")
|--------------------------------------------------------------------------
|
| Formato: 'modulo.accion' => 'Descripción'. El seeder RolesAndPermissionsSeeder
| los crea/actualiza y los asigna a los roles base. El rol "admin" pasa todas
| las verificaciones (Gate::before en AppServiceProvider).
|
*/

return [

    'permisos' => [
        // Dashboard
        'dashboard.ver'             => 'Ver el panel de inicio',

        // Punto de venta
        'pos.vender'                => 'Registrar ventas en el POS',
        'pos.descuento'             => 'Aplicar descuentos dentro del máximo permitido',
        'pos.descuento.autorizar'   => 'Autorizar descuentos mayores al máximo',
        'pos.anular'                => 'Anular ventas',
        'pos.devolver'              => 'Registrar devoluciones',
        'pos.historial'             => 'Ver historial de ventas',
        'pos.precuentas'            => 'Gestionar precuentas y pedidos pendientes',

        // Mesas
        'mesas.configurar'          => 'Configurar mesas, áreas de preparación y modificadores',

        // Caja
        'caja.abrir'                => 'Abrir turno de caja',
        'caja.cerrar'               => 'Cerrar turno de caja',
        'caja.movimientos'          => 'Registrar ingresos y egresos de caja',
        'caja.arqueo'               => 'Realizar arqueos',
        'caja.historial'            => 'Ver historial de cierres',
        'caja.reabrir'              => 'Reabrir turnos cerrados',

        // Productos e inventario
        'productos.ver'             => 'Ver catálogo de productos',
        'productos.gestionar'       => 'Crear y editar productos',
        'productos.precios'         => 'Cambiar precios y costos',
        'catalogos.gestionar'       => 'Gestionar categorías y unidades de medida',
        'inventario.ver'            => 'Ver existencias e historial de inventario',
        'inventario.registrar'      => 'Registrar entradas y salidas de inventario',
        'inventario.ajustar'        => 'Realizar ajustes de inventario',
        'inventario.autorizar_baja' => 'Autorizar salidas y ajustes a la baja de insumos de receta',
        'inventario.kardex'         => 'Ver kardex',

        // Compras y proveedores
        'proveedores.gestionar'     => 'Gestionar proveedores',
        'compras.ver'               => 'Ver compras y órdenes de compra',
        'compras.gestionar'         => 'Registrar órdenes de compra y recepciones',
        'cuentas_pagar.gestionar'   => 'Registrar pagos a proveedores',

        // Producción
        'recetas.gestionar'         => 'Gestionar recetas',
        'produccion.ver'            => 'Ver producción',
        'produccion.registrar'      => 'Registrar producción diaria',
        'mermas.registrar'          => 'Registrar mermas',

        // Clientes
        'clientes.ver'              => 'Ver clientes',
        'clientes.gestionar'        => 'Crear y editar clientes',
        'cuentas_cobrar.gestionar'  => 'Registrar abonos y ver estados de cuenta',
        'pedidos.gestionar'         => 'Gestionar pedidos especiales',
        'clientes.credito_exceder'  => 'Autorizar ventas a crédito sobre el límite del cliente',

        // Gastos
        'gastos.ver'                => 'Ver gastos',
        'gastos.registrar'          => 'Registrar gastos',
        'gastos.categorias'         => 'Crear y editar categorías de gasto',

        // Impresoras
        'impresoras.gestionar'      => 'Configurar impresoras',

        // Reportes
        'reportes.ver'              => 'Ver reportes',
        'reportes.exportar'         => 'Exportar reportes a Excel y PDF',

        // Administración
        'usuarios.gestionar'        => 'Gestionar usuarios',
        'roles.gestionar'           => 'Gestionar roles y permisos',
        'auditoria.ver'             => 'Ver registro de auditoría',
        'configuracion.gestionar'   => 'Editar la configuración del negocio',

        // Ayuda
        'manuales.ver'              => 'Ver los manuales de uso del sistema',
    ],

    // Módulo de cada permiso (lo que va antes del punto) para la matriz de roles
    'modulos' => [
        'dashboard'      => 'Panel de inicio',
        'pos'            => 'Punto de venta',
        'mesas'          => 'Mesas',
        'caja'           => 'Caja',
        'productos'      => 'Productos',
        'catalogos'      => 'Catálogos',
        'inventario'     => 'Inventario',
        'proveedores'    => 'Proveedores',
        'compras'        => 'Compras',
        'cuentas_pagar'  => 'Cuentas por pagar',
        'recetas'        => 'Recetas',
        'produccion'     => 'Producción',
        'mermas'         => 'Mermas',
        'clientes'       => 'Clientes',
        'cuentas_cobrar' => 'Cuentas por cobrar',
        'pedidos'        => 'Pedidos especiales',
        'gastos'         => 'Gastos',
        'impresoras'     => 'Impresoras',
        'reportes'       => 'Reportes',
        'usuarios'       => 'Usuarios',
        'roles'          => 'Roles y permisos',
        'auditoria'      => 'Auditoría',
        'configuracion'  => 'Configuración',
        'manuales'       => 'Manuales',
    ],

    // Permisos por rol base (el rol "admin" no necesita lista: tiene acceso total)
    'roles' => [
        // Abre su turno y atiende todo el día: ventas, mesas, caja, compras, gastos,
        // inventario (entradas y salidas), producción y mermas. Lo que no hace:
        // anular, devolver, ajustar inventario, reabrir turnos, reportes y configuración
        'mesero' => [
            'dashboard.ver',
            'pos.vender', 'pos.descuento', 'pos.historial', 'pos.precuentas',
            'caja.abrir', 'caja.cerrar', 'caja.movimientos', 'caja.arqueo',
            'productos.ver', 'inventario.ver', 'inventario.registrar', 'inventario.kardex',
            'proveedores.gestionar', 'compras.ver', 'compras.gestionar', 'cuentas_pagar.gestionar',
            'produccion.ver', 'produccion.registrar', 'mermas.registrar',
            'clientes.ver', 'clientes.gestionar', 'cuentas_cobrar.gestionar', 'pedidos.gestionar',
            'gastos.ver', 'gastos.registrar',
            'manuales.ver',
        ],
    ],

];
