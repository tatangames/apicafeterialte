<?php

namespace App\Http\Controllers\Api\Login;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class LoginApiController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = Administrador::where('email', $request->email)->first();

        // Si el correo no existe
        if (!$user) {
            return response()->json([
                'success' => false,
                'status' => 'EMAIL_NOT_FOUND',
                'message' => 'El correo electrónico no está Registrado.',
            ], 401);
        }

        // Si la contraseña es incorrecta
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'status' => 'INVALID_PASSWORD',
                'message' => 'La contraseña es incorrecta.',
            ], 401);
        }

        // Login exitoso - continúa con el token
        $deviceName = $request->device_name ?? 'default';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    // Métod protegido (requiere autenticación)
    public function logout(Request $request)
    {
        // Eliminar el token actual
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => 1,
            'message' => 'Sesión cerrada correctamente.'
        ]);
    }






    public function enviarCorreoAdministrador(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|email|exists:administradores,email',
            ],
            [
                'email.required' => 'El correo es obligatorio.',
                'email.email' => 'Ingrese un correo válido.',
                'email.exists' => 'Este correo no está registrado.',
            ]
        );

        if ($validator->fails()) {

            $errors = $validator->errors();

            if ($errors->has('email')) {
                $message = $errors->first('email');

                $code = match (true) {
                    str_contains($message, 'obligatorio') => 'EMAIL_REQUIRED',
                    str_contains($message, 'válido')      => 'EMAIL_INVALID',
                    str_contains($message, 'registrado')  => 'EMAIL_NOT_FOUND',
                    default                               => 'EMAIL_ERROR',
                };
            }

            return response()->json([
                'success' => false,
                'code'    => $code ?? 'EMAIL_ERROR',
                'message' => $message ?? 'Error de validación.',
                'errors'  => $errors,
            ], 422);
        }

        $status = Password::broker('administradores')
            ->sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'code'    => 'RESET_EMAIL_SENT',
                'message' => 'Se envió el enlace de recuperación al correo.',
            ]);
        }

        return response()->json([
            'success' => false,
            'code'    => 'RESET_EMAIL_FAILED',
            'message' => 'No se pudo enviar el correo. Intente más tarde.',
        ], 500);
    }





    public function validateResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $token = $request->token;
        $email = $request->email;

        // Buscar el token en la tabla password_reset_tokens
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        // Verificar si existe el registro
        if (!$resetRecord) {
            return response()->json([
                'message' => 'Token inválido o no encontrado'
            ], 400);
        }

        // Verificar si el token coincide
        if (!Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token ha expirado (60 minutos por defecto)
        $expiresAt = Carbon::parse($resetRecord->created_at)
            ->addMinutes(config('auth.passwords.users.expire', 60));

        if (Carbon::now()->isAfter($expiresAt)) {
            return response()->json([
                'message' => 'El token ha expirado'
            ], 400);
        }

        // Token válido
        return response()->json([
            'message' => 'Token válido'
        ]);
    }





    /**
     * Restablecer contraseña con token
     */
    public function resetPasswordConfirm(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:4|confirmed',
        ]);

        $token = $request->token;
        $email = $request->email;
        $password = $request->password;

        // Buscar el token en la tabla password_reset_tokens
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token coincide
        if (!Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token ha expirado
        $expiresAt = Carbon::parse($resetRecord->created_at)
            ->addMinutes(config('auth.passwords.users.expire', 60));

        if (Carbon::now()->isAfter($expiresAt)) {
            return response()->json([
                'message' => 'El token ha expirado'
            ], 400);
        }

        // Buscar el usuario
        $user = Administrador::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Actualizar la contraseña
        $user->password = Hash::make($password);
        $user->save();

        // Eliminar el token usado
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        return response()->json([
            'message' => 'Contraseña restablecida exitosamente'
        ]);
    }
}
