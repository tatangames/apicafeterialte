<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use App\Services\AuditoriaService;
use App\Services\AutorizacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class AuthApiController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'nombre' => $user->nombre,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'tiene_pin' => !empty($user->pin),
            'pin_actualizado_at' => $user->pin_actualizado_at?->format('Y-m-d H:i'),
        ]);
    }

    // PUT /me/pin {password_actual, pin, pin_confirmation}: cada quien cambia su PIN de autorización
    public function cambiarPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password_actual' => 'required|string',
            'pin'             => 'required|digits_between:4,6|confirmed',
        ], [
            'password_actual.required' => 'Escribe tu contraseña actual',
            'pin.required'             => 'Escribe el PIN nuevo',
            'pin.digits_between'       => 'El PIN debe tener de 4 a 6 números',
            'pin.confirmed'            => 'La confirmación no coincide con el PIN',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!Hash::check($request->input('password_actual'), $user->password)) {
            return response()->json(['success' => false, 'message' => 'La contraseña actual no es correcta'], 422);
        }
        if (AutorizacionService::pinEnUso($request->input('pin'), $user->id)) {
            return response()->json(['success' => false, 'message' => 'Ese PIN no está disponible, elige otro'], 422);
        }

        $antes = $user->pin_actualizado_at?->toDateTimeString();
        $user->forceFill(['pin' => $request->input('pin'), 'pin_actualizado_at' => now()])->save();

        AuditoriaService::registrar('usuarios', 'cambiar_pin', $user, ['pin_actualizado_at' => $antes], ['pin_actualizado_at' => $user->pin_actualizado_at->toDateTimeString()]);

        return response()->json([
            'success' => 1,
            'message' => 'PIN guardado',
            'pin_actualizado_at' => $user->pin_actualizado_at->format('Y-m-d H:i'),
        ]);
    }

    // POST /me/desbloquear {codigo}: quita el bloqueo de pantalla con tu PIN (o tu contraseña si no tienes PIN)
    public function desbloquear(Request $request)
    {
        $codigo = trim((string) $request->input('codigo'));
        if ($codigo === '') {
            return response()->json(['success' => false, 'message' => 'Escribe tu PIN'], 422);
        }

        $user = $request->user();
        $clave = 'desbloquear:' . $user->id;
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            $segundos = RateLimiter::availableIn($clave);
            return response()->json(['success' => false, 'message' => "Demasiados intentos. Espera {$segundos} segundos.", 'segundos' => $segundos], 429);
        }

        $valido = ($user->pin && Hash::check($codigo, $user->pin)) || Hash::check($codigo, $user->password);
        if (!$valido) {
            RateLimiter::hit($clave, 60);
            return response()->json(['success' => false, 'message' => $user->pin ? 'PIN incorrecto' : 'Contraseña incorrecta'], 422);
        }

        RateLimiter::clear($clave);
        return response()->json(['success' => 1]);
    }

    // DELETE /admin/usuarios/{usuario}/pin: el administrador borra el PIN de quien lo olvidó;
    // esa persona desbloquea con su contraseña y crea uno nuevo en "Mi cuenta"
    public function resetearPin(Administrador $usuario)
    {
        if (!$usuario->pin) {
            return response()->json(['success' => false, 'message' => "{$usuario->nombre} no tiene PIN"], 422);
        }

        $antes = $usuario->pin_actualizado_at?->toDateTimeString();
        $usuario->forceFill(['pin' => null, 'pin_actualizado_at' => null])->save();

        AuditoriaService::registrar('usuarios', 'resetear_pin', $usuario, ['pin_actualizado_at' => $antes], ['pin_actualizado_at' => null]);

        return response()->json(['success' => 1, 'message' => "PIN de {$usuario->nombre} borrado. Ahora entra con su contraseña y crea uno nuevo."]);
    }
}
