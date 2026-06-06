<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Login\LoginApiController;



Route::post('/login', [LoginApiController::class, 'login']);

Route::post('/validate-reset-token', [LoginApiController::class, 'validateResetToken']);
Route::post('/reset-password-confirm', [LoginApiController::class, 'resetPasswordConfirm']);
Route::post('/admin/enviar/correo/password', [LoginApiController::class, 'enviarCorreoAdministrador']);

// Rutas protegidas (requieren token)
Route::middleware('auth:sanctum')->group(function () {

    // Cerrar sesión
    Route::post('/logout', [LoginApiController::class, 'logout']);




});
