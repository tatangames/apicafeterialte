<?php

namespace Tests\Feature;

use App\Models\ConfiguracionFel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacturacionElectronicaTest extends TestCase
{
    private function datosCompletos(array $extra = []): array
    {
        return array_merge([
            'activo'               => true,
            'ambiente'             => '00',
            'proveedor'            => 'hacienda',
            'api_password'         => 'claveApi123',
            'firmador_url'         => 'http://localhost:8113/firmardocumento/',
            'clave_privada'        => 'llave456',
            'nit'                  => '0614-280390-112-1',
            'nrc'                  => '123456-7',
            'razon_social'         => 'Eduardo Pérez',
            'nombre_comercial'     => 'Panadería Eduardo',
            'actividades'          => [['codigo' => '10712', 'descripcion' => 'Fabricación de pan, galletas y barquillos']],
            'tipo_establecimiento' => '01',
            'departamento'         => '06',
            'municipio'            => '23',
            'distrito'             => 'San Salvador',
            'direccion'            => 'Col. Escalón, local 5',
            'telefono'             => '2222-0000',
            'correo'               => 'panaderia@test.com',
            'tipos_dte'            => ['01', '03'],
        ], $extra);
    }

    public function test_solo_quien_gestiona_configuracion_ve_la_fel(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/configuracion/fel')->assertStatus(403);
        $this->putJson('/api/configuracion/fel', [])->assertStatus(403);
    }

    public function test_inactiva_se_guarda_aunque_falten_datos(): void
    {
        $this->actuarComo('admin');

        $this->getJson('/api/configuracion/fel')
            ->assertOk()
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('catalogos.departamentos.06.municipios.23.nombre', 'San Salvador Centro');

        $this->putJson('/api/configuracion/fel', ['activo' => false, 'ambiente' => '00', 'proveedor' => 'hacienda', 'nit' => '0614-280390-112-1'])
            ->assertOk()
            ->assertJsonPath('data.nit', '06142803901121');
    }

    public function test_activa_exige_datos_y_valida_distrito_del_municipio(): void
    {
        $this->actuarComo('admin');

        $this->putJson('/api/configuracion/fel', ['activo' => true, 'ambiente' => '00', 'proveedor' => 'hacienda'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nit', 'nrc', 'actividades', 'departamento', 'api_password', 'clave_privada']);

        $this->putJson('/api/configuracion/fel', $this->datosCompletos(['distrito' => 'Santa Tecla']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['distrito']);
    }

    public function test_guarda_cifrado_y_no_devuelve_contrasenas(): void
    {
        $this->actuarComo('admin');

        $res = $this->putJson('/api/configuracion/fel', $this->datosCompletos())
            ->assertOk()
            ->assertJsonPath('data.nrc', '1234567')
            ->assertJsonPath('data.tiene_api_password', true)
            ->assertJsonPath('data.tiene_clave_privada', true)
            ->assertJsonMissingPath('data.api_password')
            ->assertJsonMissingPath('data.clave_privada');

        $crudo = DB::table('configuracion_fel')->value('api_password');
        $this->assertNotSame('claveApi123', $crudo);
        $this->assertSame('claveApi123', ConfiguracionFel::actual()->api_password);

        // Vacía = no cambiar
        $this->putJson('/api/configuracion/fel', $this->datosCompletos(['api_password' => '', 'clave_privada' => '']))->assertOk();
        $this->assertSame('claveApi123', ConfiguracionFel::actual()->api_password);
    }

    public function test_probar_conexion_con_hacienda_guarda_token(): void
    {
        $this->actuarComo('admin');
        $this->putJson('/api/configuracion/fel', $this->datosCompletos())->assertOk();

        Http::fake(['apitest.dtes.mh.gob.sv/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer abc']])]);

        $this->postJson('/api/configuracion/fel/probar')
            ->assertOk()
            ->assertJsonPath('success', 1)
            ->assertJsonPath('data.ultima_prueba_ok', true);

        Http::assertSent(fn ($r) => $r['user'] === '06142803901121' && $r['pwd'] === 'claveApi123');
        $this->assertSame('Bearer abc', ConfiguracionFel::actual()->token);
    }

    public function test_probar_conexion_rechazada_queda_registrada(): void
    {
        $this->actuarComo('admin');
        $this->putJson('/api/configuracion/fel', $this->datosCompletos())->assertOk();

        Http::fake(['*' => Http::response(['status' => 'ERROR', 'body' => ['descripcionMsg' => 'Usuario no válido']], 401)]);

        $this->postJson('/api/configuracion/fel/probar')
            ->assertOk()
            ->assertJsonPath('success', 0)
            ->assertJsonPath('data.ultima_prueba_ok', false);

        $this->assertStringContainsString('Usuario no válido', ConfiguracionFel::actual()->ultima_prueba_mensaje);
    }
}
