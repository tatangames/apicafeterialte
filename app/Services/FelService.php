<?php

namespace App\Services;

use App\Models\ConfiguracionFel;
use Illuminate\Support\Facades\Http;

/**
 * Punto único de entrada para la facturación electrónica.
 *
 * Hoy solo prueba la conexión (autenticación con Hacienda). Aquí se agregarán:
 *   - generarDte(Venta)   → arma el JSON según el esquema del tipo de documento
 *   - firmar(array)       → POST al firmador del MH con la clave privada
 *   - transmitir(string)  → POST {url}/fesv/recepciondte con el token
 *   - invalidar(...)      → evento de invalidación
 * Ventas/POS solo deberán llamar a este servicio y revisar estaActivo().
 */
class FelService
{
    public static function estaActivo(): bool
    {
        return ConfiguracionFel::actual()->activo;
    }

    /**
     * Obtiene (o reutiliza) el token de Hacienda. Lanza excepción si falla.
     */
    public function token(ConfiguracionFel $fel, bool $forzar = false): string
    {
        if (!$forzar && $fel->token && $fel->token_expira_at?->isFuture()) {
            return $fel->token;
        }

        $resp = Http::asForm()
            ->timeout(20)
            ->acceptJson()
            ->withUserAgent('PanaderiaPOS/1.0')
            ->post($fel->urlHacienda() . '/seguridad/auth', [
                'user' => $fel->api_usuario ?: $fel->nit,
                'pwd'  => $fel->api_password,
            ]);

        $json = $resp->json() ?? [];
        if (!$resp->successful() || ($json['status'] ?? null) !== 'OK' || empty($json['body']['token'])) {
            $detalle = $json['body']['descripcionMsg'] ?? $json['message'] ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException('Hacienda rechazó la autenticación: ' . $detalle);
        }

        // Pruebas: 48 h, producción: 24 h. Se renueva una hora antes por seguridad.
        $horas = $fel->ambiente === '01' ? 23 : 47;
        $fel->update(['token' => $json['body']['token'], 'token_expira_at' => now()->addHours($horas)]);

        return $json['body']['token'];
    }

    /**
     * Verifica credenciales y deja guardado el resultado.
     * @return array{ok: bool, mensaje: string}
     */
    public function probarConexion(ConfiguracionFel $fel): array
    {
        try {
            if ($fel->proveedor === 'hacienda') {
                if (!$fel->api_password || !($fel->api_usuario ?: $fel->nit)) {
                    throw new \RuntimeException('Falta el usuario (NIT) o la contraseña de la API de Hacienda.');
                }
                $this->token($fel, true);
                $mensaje = 'Autenticación correcta con Hacienda (' . config("fel.ambientes.{$fel->ambiente}.nombre") . ').';
            } else {
                if (!$fel->api_url) {
                    throw new \RuntimeException('Falta la URL de la API del certificador.');
                }
                $resp = Http::timeout(15)->get($fel->api_url);
                if ($resp->serverError()) {
                    throw new \RuntimeException('El certificador respondió con error HTTP ' . $resp->status());
                }
                $mensaje = 'El certificador responde (HTTP ' . $resp->status() . '). La autenticación se configurará al integrar su API.';
            }
            $ok = true;
        } catch (\Throwable $e) {
            $ok      = false;
            $mensaje = $e instanceof \RuntimeException ? $e->getMessage() : 'No se pudo conectar: ' . $e->getMessage();
        }

        $fel->update([
            'ultima_prueba_at'      => now(),
            'ultima_prueba_ok'      => $ok,
            'ultima_prueba_mensaje' => mb_substr($mensaje, 0, 500),
        ]);

        return ['ok' => $ok, 'mensaje' => $mensaje];
    }
}
