<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionFel;
use App\Services\AuditoriaService;
use App\Services\FelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FelApiController extends Controller
{
    // GET /configuracion/fel
    public function mostrar()
    {
        return response()->json([
            'success'   => 1,
            'data'      => ConfiguracionFel::actual(),
            'catalogos' => [
                'ambientes'             => collect(config('fel.ambientes'))->map(fn ($a) => $a['nombre']),
                'proveedores'           => config('fel.proveedores'),
                'tipos_dte'             => config('fel.tipos_dte'),
                'tipos_establecimiento' => config('fel.tipos_establecimiento'),
                'actividades'           => config('fel.actividades_sugeridas'),
                'departamentos'         => config('fel.departamentos'),
                'firmador_url_defecto'  => config('fel.firmador_url_defecto'),
            ],
        ]);
    }

    // PUT /configuracion/fel
    public function actualizar(Request $request)
    {
        // NIT y NRC se guardan solo con dígitos, como los pide el esquema del DTE
        $request->merge([
            'nit' => $request->filled('nit') ? preg_replace('/\D/', '', $request->input('nit')) : null,
            'nrc' => $request->filled('nrc') ? preg_replace('/\D/', '', $request->input('nrc')) : null,
        ]);

        $fel    = ConfiguracionFel::actual();
        $activo = $request->boolean('activo');
        $req    = $activo ? 'required' : 'nullable';
        $deptos = config('fel.departamentos');
        $depto  = $request->input('departamento');
        $muni   = $request->input('municipio');

        $validator = validator($request->all(), [
            'activo'                 => 'required|boolean',
            'ambiente'               => ['required', Rule::in(array_keys(config('fel.ambientes')))],
            'proveedor'              => ['required', Rule::in(array_keys(config('fel.proveedores')))],
            'api_url'                => [Rule::requiredIf($activo && $request->input('proveedor') === 'externo'), 'nullable', 'url', 'max:300'],
            'api_usuario'            => 'nullable|string|max:100',
            'api_password'           => 'nullable|string|max:200',
            'firmador_url'           => [Rule::requiredIf($activo && $request->input('proveedor') === 'hacienda'), 'nullable', 'url', 'max:300'],
            'clave_privada'          => 'nullable|string|max:200',
            'nit'                    => [$req, 'regex:/^(\d{9}|\d{14})$/'],
            'nrc'                    => [$req, 'regex:/^\d{1,8}$/'],
            'razon_social'           => "$req|string|max:250",
            'nombre_comercial'       => 'nullable|string|max:150',
            'actividades'            => $activo ? 'required|array|min:1' : 'nullable|array',
            'actividades.*.codigo'   => 'required|regex:/^\d{5,6}$/',
            'actividades.*.descripcion' => 'required|string|max:150',
            'tipo_establecimiento'   => [$req, Rule::in(array_keys(config('fel.tipos_establecimiento')))],
            'cod_establecimiento_mh' => 'nullable|string|size:4',
            'cod_establecimiento'    => 'nullable|string|max:10',
            'cod_punto_venta_mh'     => 'nullable|string|size:4',
            'cod_punto_venta'        => 'nullable|string|max:15',
            'departamento'           => [$req, Rule::in(array_keys($deptos))],
            'municipio'              => [$req, Rule::in(array_keys($deptos[$depto]['municipios'] ?? []))],
            'distrito'               => [$req, Rule::in($deptos[$depto]['municipios'][$muni]['distritos'] ?? [])],
            'direccion'              => "$req|string|max:200",
            'telefono'               => [$req, 'regex:/^[0-9+\-\s()]{8,30}$/'],
            'correo'                 => "$req|email|max:100",
            'tipos_dte'              => $activo ? 'required|array|min:1' : 'nullable|array',
            'tipos_dte.*'            => Rule::in(array_keys(config('fel.tipos_dte'))),
        ], [
            'nit.required'           => 'El NIT es requerido para activar la facturación electrónica',
            'nit.regex'              => 'El NIT debe tener 14 dígitos (o 9 si es DUI homologado)',
            'nrc.required'           => 'El NRC es requerido',
            'nrc.regex'              => 'El NRC debe tener de 1 a 8 dígitos',
            'razon_social.required'  => 'La razón social es requerida',
            'actividades.required'   => 'Agregue al menos una actividad económica',
            'actividades.min'        => 'Agregue al menos una actividad económica',
            'actividades.*.codigo.required' => 'Cada actividad necesita su código',
            'actividades.*.codigo.regex'    => 'El código de actividad debe tener 5 o 6 dígitos',
            'actividades.*.descripcion.required' => 'Cada actividad necesita su descripción',
            'tipo_establecimiento.required' => 'Seleccione el tipo de establecimiento',
            'cod_establecimiento_mh.size'   => 'El código de establecimiento MH tiene 4 caracteres (ej. M001)',
            'cod_punto_venta_mh.size'       => 'El código de punto de venta MH tiene 4 caracteres (ej. P001)',
            'departamento.required'  => 'Seleccione el departamento',
            'municipio.required'     => 'Seleccione el municipio',
            'municipio.in'           => 'El municipio no pertenece al departamento seleccionado',
            'distrito.required'      => 'Seleccione el distrito',
            'distrito.in'            => 'El distrito no pertenece al municipio seleccionado',
            'direccion.required'     => 'La dirección es requerida',
            'telefono.required'      => 'El teléfono es requerido',
            'telefono.regex'         => 'El teléfono no es válido',
            'correo.required'        => 'El correo es requerido',
            'correo.email'           => 'El correo no es válido',
            'api_url.required'       => 'Ingrese la URL de la API del certificador',
            'firmador_url.required'  => 'Ingrese la URL del firmador',
            'tipos_dte.required'     => 'Seleccione al menos un tipo de documento',
        ]);

        $validator->after(function ($v) use ($activo, $request, $fel) {
            if ($activo && $request->input('proveedor') === 'hacienda'
                && !$request->filled('api_password') && !$fel->tiene_api_password) {
                $v->errors()->add('api_password', 'Ingrese la contraseña de la API de Hacienda');
            }
            if ($activo && $request->input('proveedor') === 'hacienda'
                && !$request->filled('clave_privada') && !$fel->tiene_clave_privada) {
                $v->errors()->add('clave_privada', 'Ingrese la contraseña de la llave privada del certificado');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $antes = $fel->toArray();

        try {
            $datos = $validator->validated();
            $datos['actividades'] = array_values($datos['actividades'] ?? []);
            $datos['tipos_dte']   = array_values(array_unique($datos['tipos_dte'] ?? []));

            // Las contraseñas vacías significan "no cambiar"
            foreach (['api_password', 'clave_privada'] as $secreto) {
                if (blank($datos[$secreto] ?? null)) {
                    unset($datos[$secreto]);
                }
            }

            // Si cambian credenciales o ambiente, el token anterior ya no sirve
            if (isset($datos['api_password'])
                || ($datos['ambiente'] ?? null) !== $fel->ambiente
                || ($datos['api_usuario'] ?? null) !== $fel->api_usuario) {
                $datos['token']           = null;
                $datos['token_expira_at'] = null;
            }

            $fel->update($datos);

            AuditoriaService::registrar('configuracion', 'actualizar_fel', $fel, $antes, $fel->fresh()->toArray());

            return response()->json([
                'success' => 1,
                'message' => 'Configuración de facturación electrónica guardada',
                'data'    => $fel->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al guardar configuración FEL: ' . $e->getMessage());

            return response()->json(['success' => 0, 'message' => 'Error al guardar la configuración'], 500);
        }
    }

    // POST /configuracion/fel/probar
    public function probar(FelService $servicio)
    {
        $fel       = ConfiguracionFel::actual();
        $resultado = $servicio->probarConexion($fel);

        return response()->json([
            'success' => $resultado['ok'] ? 1 : 0,
            'message' => $resultado['mensaje'],
            'data'    => $fel->fresh(),
        ]);
    }
}
