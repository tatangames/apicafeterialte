<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //$middleware->redirectGuestsTo(fn () => abort(401, 'Usuario no autenticado'));
        // NO EXISTE VISTA PARA REDIRECCIONAR POR SER API
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // 401 - No autenticado
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'UNAUTHENTICATED',
                'message' => 'No autenticado. Token inválido o no proporcionado.',
            ], 401);
        });

        // 403 - Sin permisos
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'FORBIDDEN',
                'message' => 'No tienes permisos para realizar esta acción.',
            ], 403);
        });

        // 422 - Validación
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'VALIDATION_ERROR',
                'message' => 'Error en los datos enviados.',
                'errors'  => $e->errors(),
            ], 422);
        });

        // 404 - Modelo no encontrado
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            $model = class_basename($e->getModel());
            return response()->json([
                'success' => false,
                'status'  => 'NOT_FOUND',
                'message' => "El recurso '{$model}' no fue encontrado.",
            ], 404);
        });

        // 404 - Ruta no encontrada
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'ROUTE_NOT_FOUND',
                'message' => 'La ruta solicitada no existe.',
            ], 404);
        });

        // 405 - Método no permitido
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'METHOD_NOT_ALLOWED',
                'message' => 'Método HTTP no permitido para esta ruta.',
            ], 405);
        });

        // 429 - Demasiadas peticiones
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'TOO_MANY_REQUESTS',
                'message' => 'Demasiadas peticiones. Intenta de nuevo más tarde.',
            ], 429);
        });

        // 500 - Error interno (catch-all)
        $exceptions->render(function (\Throwable $e, Request $request) {
            $isDebug = config('app.debug');

            return response()->json([
                'success' => false,
                'status'  => 'SERVER_ERROR',
                'message' => $isDebug ? $e->getMessage() : 'Error interno del servidor.',
                'debug'   => $isDebug ? [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ] : null,
            ], 500);
        });

    })->create();
