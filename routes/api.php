<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Login\LoginApiController;
use App\Http\Controllers\Api\Auth\AuthApiController;
use App\Http\Controllers\Api\Auth\DashboardApiController;
use App\Http\Controllers\Api\Config\RolesApiController;
use App\Http\Controllers\Api\Config\ConfiguracionApiController;
use App\Http\Controllers\Api\Productos\ProductosApiController;



Route::post('/login', [LoginApiController::class, 'login']);

Route::post('/validate-reset-token', [LoginApiController::class, 'validateResetToken']);
Route::post('/reset-password-confirm', [LoginApiController::class, 'resetPasswordConfirm']);
Route::post('/admin/enviar/correo/password', [LoginApiController::class, 'enviarCorreoAdministrador']);


Route::post('/printer/test',   [ConfiguracionApiController::class, 'testPrint']);
Route::post('/printer/venta',  [ConfiguracionApiController::class, 'imprimirVenta']);

Route::post('/productos/registro', [ProductosApiController::class, 'registrarProducto']);
Route::get('/productos/tabla', [ProductosApiController::class, 'tablaProductos']);
Route::get('/productos/editar/{id}', [ProductosApiController::class, 'mostrarProducto']);
Route::post('/productos/actualizar/{id}', [ProductosApiController::class, 'actualizarProducto']);

// Rutas protegidas (requieren token)
Route::middleware('auth:sanctum')->group(function () {

    // Cerrar sesión
    Route::post('/logout', [LoginApiController::class, 'logout']);

    // Usuario autenticado + roles + permisos (CLAVE)
    Route::get('/me', [AuthApiController::class, 'me']);

    // Información del usuario autenticado
    Route::get('/datos', [DashboardApiController::class, 'datos']);

    // ROLES Y PERMISOS
    Route::get('/admin/roles/tabla', [RolesApiController::class,'listadoRoles']);
    Route::post('/admin/roles/borrar-global', [RolesApiController::class, 'borrarRolGlobal']);
    Route::get('/admin/roles/permisos/tabla/{id}', [RolesApiController::class,'tablaRolesPermisos']);
    Route::post('/admin/roles/permiso/borrar', [RolesApiController::class, 'borrarPermiso']);
    Route::post('/admin/roles/nuevo-rol', [RolesApiController::class, 'nuevoRol']);
    Route::post('/admin/roles/permiso/agregar', [RolesApiController::class, 'agregarPermiso']);
    Route::get('/admin/roles/permisos-todos/tabla', [RolesApiController::class,'tablaTodosPermisos']);
    Route::post('/admin/permisos/extra-borrar', [RolesApiController::class, 'borrarPermisoGlobal']);
    Route::post('/admin/permisos/extra-nuevo', [RolesApiController::class, 'nuevoPermisoExtra']);
    Route::get('/admin/usuarios/tabla', [RolesApiController::class,'tablaUsuarios']);
    Route::post('/admin/permisos/nuevo-usuario', [RolesApiController::class, 'nuevoUsuario']);
    Route::post('/admin/informacion/administrador', [RolesApiController::class, 'informacionAdministrador']);
    Route::put('/admin/actualizar/administrador/{id}', [RolesApiController::class, 'actualizarAdministrador']);

    // CATEGORIAS
    Route::get('/admin/categorias/tabla', [ConfiguracionApiController::class,'tablaCategorias']);
    Route::post('/admin/categorias/nuevo', [ConfiguracionApiController::class, 'registrarCategoria']);
    Route::put('/admin/categorias/actualizar/{id}', [ConfiguracionApiController::class, 'actualizarCategoria']);


    // UNIDAD DE MEDIDA
    Route::get('/admin/unidadmedida/tabla', [ConfiguracionApiController::class,'tablaUnidadMedida']);
    Route::post('/admin/unidadmedida/nuevo', [ConfiguracionApiController::class, 'registrarUnidadMedida']);
    Route::put('/admin/unidadmedida/actualizar/{id}', [ConfiguracionApiController::class, 'actualizarUnidadMedida']);








});
