<?php

namespace Tests\Feature;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Impresora;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Services\CajaService;
use App\Support\Impresion\EscPos;
use App\Support\Impresion\ImpresionException;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

// Transporte de prueba: guarda lo que se "imprime" o simula una impresora apagada
class TransporteFalso implements Transporte
{
    public array $enviados = [];
    public bool $falla = false;

    public function enviar(Impresora $impresora, string $bytes): void
    {
        if ($this->falla) {
            throw new ImpresionException("No se pudo conectar con {$impresora->alias}");
        }
        $this->enviados[] = ['impresora' => $impresora->alias, 'bytes' => $bytes];
    }

    public function texto(int $i = 0): string
    {
        return mb_convert_encoding($this->enviados[$i]['bytes'], 'UTF-8', 'CP850');
    }
}

class ImpresionTest extends TestCase
{
    private TransporteFalso $transporte;
    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $pan;
    private Producto $cafe;
    private Categoria $panes;
    private Categoria $bebidas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->transporte = new TransporteFalso();
        $this->app->instance(TransporteRed::class, $this->transporte);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->first();
        $this->panes = Categoria::create(['nombre' => 'Panes', 'estado' => true]);
        $this->bebidas = Categoria::create(['nombre' => 'Bebidas', 'estado' => true]);

        $this->pan = Producto::create(['nombre' => 'Pan francés', 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => 0.15, 'unidad_medida_id' => $unidad->id]);
        $this->cafe = Producto::create(['nombre' => 'Café con leche', 'tipo' => 'bien', 'clase' => 'reventa', 'precio_venta' => 1.25, 'unidad_medida_id' => $unidad->id]);
        Inventario::create(['producto_id' => $this->pan->id, 'stock_actual' => 100]);
        Inventario::create(['producto_id' => $this->cafe->id, 'stock_actual' => 100]);
        $this->pan->categorias()->sync([$this->panes->id]);
        $this->cafe->categorias()->sync([$this->bebidas->id]);

        $this->turno = CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);
    }

    private function impresora(array $datos = []): Impresora
    {
        return Impresora::create(array_merge([
            'alias' => 'Mostrador', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'cash_drawer' => 'pin2', 'activo' => true,
        ], $datos));
    }

    private function vender(array $items, string $metodo = 'Efectivo', float $monto = 10): int
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'items' => $items,
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', $metodo)->value('id'), 'monto' => $monto]],
        ])->assertCreated()->json('venta.id');
    }

    // ── Ticket ──────────────────────────────────────────────────

    public function test_ticket_lleva_datos_de_la_venta_corta_y_abre_el_cajon_si_hubo_efectivo(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 10]], 'Efectivo', 5);

        $this->postJson("/api/impresion/ventas/{$id}", ['caja_id' => $this->caja->id])
            ->assertOk()->assertJsonPath('impreso', true)->assertJsonPath('trabajos.0.estado', 'impreso');

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('Panadería Eduardo', $texto);
        $this->assertStringContainsString('Ticket T-000001', $texto);
        $this->assertStringContainsString('Pan francés', $texto);
        $this->assertStringContainsString('$1.50', $texto);
        $this->assertStringContainsString('Cambio', $texto);

        $bytes = $this->transporte->enviados[0]['bytes'];
        $this->assertStringContainsString("\x1Bp\x00\x19\xFA", $bytes);   // cajón por el pin 2
        $this->assertStringContainsString("\x1DV\x42\x00", $bytes);       // corte de papel
    }

    public function test_reimpresion_es_copia_y_no_abre_el_cajon(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->postJson("/api/impresion/ventas/{$id}", ['copia' => true])->assertJsonPath('impreso', true);

        $this->assertStringContainsString('*** COPIA ***', $this->transporte->texto());
        $this->assertStringNotContainsString("\x1Bp", $this->transporte->enviados[0]['bytes']);
    }

    public function test_pago_con_tarjeta_no_abre_el_cajon(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->cafe->id, 'cantidad' => 1]], 'Tarjeta', 1.25);

        $this->postJson("/api/impresion/ventas/{$id}")->assertJsonPath('impreso', true);
        $this->assertStringNotContainsString("\x1Bp", $this->transporte->enviados[0]['bytes']);
    }

    public function test_usa_la_impresora_de_la_caja_antes_que_la_predeterminada(): void
    {
        $this->impresora(['alias' => 'General', 'predeterminada' => true]);
        $propia = $this->impresora(['alias' => 'Caja 1', 'ip_impresora' => '192.168.1.51']);
        $this->caja->update(['impresora_id' => $propia->id]);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], 'Efectivo', 1);
        $this->postJson("/api/impresion/ventas/{$id}", ['caja_id' => $this->caja->id])->assertOk();

        $this->assertSame('Caja 1', $this->transporte->enviados[0]['impresora']);
    }

    public function test_sin_impresora_configurada_responde_con_el_motivo(): void
    {
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->postJson("/api/impresion/ventas/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay impresora de tickets configurada para esta caja');
    }

    // ── Errores y reintentos ────────────────────────────────────

    public function test_impresora_apagada_queda_registrada_y_se_puede_reintentar(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->transporte->falla = true;
        $trabajoId = $this->postJson("/api/impresion/ventas/{$id}")
            ->assertOk()
            ->assertJsonPath('impreso', false)
            ->assertJsonPath('message', 'No se pudo conectar con Mostrador')
            ->json('trabajos.0.id');

        $this->assertSame('error', TrabajoImpresion::find($trabajoId)->estado);

        $this->transporte->falla = false;
        $this->postJson("/api/impresion/trabajos/{$trabajoId}/reintentar")->assertJsonPath('impreso', true);

        $trabajo = TrabajoImpresion::find($trabajoId);
        $this->assertSame('impreso', $trabajo->estado);
        $this->assertSame(2, $trabajo->intentos);
        $this->assertCount(1, $this->transporte->enviados);
    }

    public function test_listado_de_trabajos_solo_para_quien_gestiona_impresoras(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/impresion/trabajos')->assertStatus(403);

        $this->actuarComo('admin');
        $this->getJson('/api/impresion/trabajos?estado=error')->assertOk()->assertJsonPath('total', 0);
    }

    // ── Comandas ────────────────────────────────────────────────

    // Categoría → área de preparación → impresora del área
    private function area(string $nombre, Categoria $categoria, ?Impresora $impresora): void
    {
        $area = AreaPreparacion::where('nombre', $nombre)->first();
        $area->update(['impresora_id' => $impresora?->id]);
        $categoria->update(['area_preparacion_id' => $area->id]);
    }

    public function test_comandas_se_reparten_por_area(): void
    {
        $this->impresora(['predeterminada' => true]);
        $horno = $this->impresora(['alias' => 'Horno', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.60', 'cash_drawer' => 'ninguno']);
        $barra = $this->impresora(['alias' => 'Barra', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.61', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->panes, $horno);
        $this->area('Barra', $this->bebidas, $barra);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 6], ['producto_id' => $this->cafe->id, 'cantidad' => 2]], 'Efectivo', 5);

        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(3, 'trabajos');

        $porImpresora = collect($this->transporte->enviados)->keyBy('impresora');
        $horno = mb_convert_encoding($porImpresora['Horno']['bytes'], 'UTF-8', 'CP850');
        $barra = mb_convert_encoding($porImpresora['Barra']['bytes'], 'UTF-8', 'CP850');

        $this->assertStringContainsString('COCINA', $horno);
        $this->assertStringContainsString('6  Pan francés', $horno);
        $this->assertStringNotContainsString('Café', $horno);
        $this->assertStringContainsString('2  Café con leche', $barra);
        $this->assertStringNotContainsString('$', $barra); // la comanda no lleva precios
    }

    public function test_venta_de_mostrador_sin_area_sale_en_comanda_general(): void
    {
        $this->impresora(['predeterminada' => true]); // solo la de tickets
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 6]], 'Efectivo', 1);

        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(2, 'trabajos');

        $comanda = $this->transporte->texto(1);
        $this->assertStringContainsString('COMANDA', $comanda);
        $this->assertStringContainsString('6  Pan francés', $comanda);
    }

    public function test_comandas_de_precuenta_con_notas(): void
    {
        $horno = $this->impresora(['alias' => 'Horno', 'uso' => 'comanda', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->panes, $horno);
        $this->actuarComo('cajero');

        $pedidoId = $this->postJson('/api/pos/pedidos', [
            'uuid' => (string) Str::uuid(), 'nombre' => 'Encargo Sra. Ana',
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 30, 'notas' => 'bien tostado']],
        ])->json('pedido.id');

        $this->postJson("/api/impresion/pedidos/{$pedidoId}/comandas")->assertJsonPath('impreso', true);

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('Encargo Sra. Ana', $texto);
        $this->assertStringContainsString('> bien tostado', $texto);
    }

    // ── Cierre, prueba y cajón ──────────────────────────────────

    public function test_cierre_de_caja_impreso(): void
    {
        $this->impresora(['predeterminada' => true]);
        $usuario = $this->actuarComo('admin');
        CajaService::cerrar($this->turno, ['10' => 1, '5' => 1, '1' => 4], null, $usuario->id);

        $this->postJson("/api/impresion/turnos/{$this->turno->id}")->assertJsonPath('impreso', true);

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('CIERRE DE CAJA', $texto);
        $this->assertStringContainsString('FALTANTE', $texto);
        $this->assertStringContainsString('$1.00', $texto);
    }

    public function test_boton_de_prueba_muestra_el_error_de_conexion(): void
    {
        $impresora = $this->impresora();
        $this->actuarComo('admin');

        $this->postJson("/api/admin/impresora/{$impresora->id}/test")->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PRUEBA DE IMPRESIÓN', $this->transporte->texto());

        $this->transporte->falla = true;
        $this->postJson("/api/admin/impresora/{$impresora->id}/test")
            ->assertStatus(422)->assertJsonPath('message', 'No se pudo conectar con Mostrador');
    }

    public function test_abrir_cajon_exige_motivo_y_queda_auditado(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');

        $this->postJson('/api/impresion/cajon', ['caja_id' => $this->caja->id])->assertStatus(422);
        $this->postJson('/api/impresion/cajon', ['caja_id' => $this->caja->id, 'motivo' => 'Dar cambio'])->assertJsonPath('impreso', true);

        $this->assertSame("\x1Bp\x00\x19\xFA", substr($this->transporte->enviados[0]['bytes'], -5));
        $this->assertDatabaseHas('auditorias', ['accion' => 'abrir_cajon', 'descripcion' => 'Dar cambio']);
    }

    // ── Configuración ───────────────────────────────────────────

    public function test_validaciones_de_impresora_y_una_sola_predeterminada(): void
    {
        $this->actuarComo('admin');
        $base = ['alias' => 'Nueva', 'conexion' => 'red', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'cash_drawer' => 'ninguno'];

        $this->postJson('/api/admin/impresora/store', $base)->assertStatus(422)->assertJsonValidationErrors('ip_impresora');
        $this->postJson('/api/admin/impresora/store', $base + ['ip_impresora' => '300.1.1.1'])->assertStatus(422);
        $this->postJson('/api/admin/impresora/store', ['conexion' => 'windows'] + $base)->assertStatus(422)->assertJsonValidationErrors('nombre_windows');

        $a = $this->postJson('/api/admin/impresora/store', $base + ['ip_impresora' => '192.168.1.80', 'predeterminada' => true])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/admin/impresora/store', ['alias' => 'Otra', 'ip_impresora' => '192.168.1.81', 'predeterminada' => true] + $base)->assertCreated()->json('data.id');

        $this->assertFalse(Impresora::find($a)->predeterminada);
        $this->assertTrue(Impresora::find($b)->predeterminada);
        $this->assertSame(9100, Impresora::find($b)->puerto);
    }

    // ── ESC/POS ─────────────────────────────────────────────────

    public function test_escpos_alinea_columnas_y_codifica_acentos(): void
    {
        $doc = (new EscPos(32))->columnas('Total', '$12.50');
        $linea = substr($doc->bytes(), 5); // sin la inicialización (ESC @ ESC t 2)

        $this->assertSame(33, strlen($linea)); // 32 caracteres + salto
        $this->assertStringEndsWith("\$12.50\n", $linea);

        $this->assertSame("\xA4", EscPos::codificar('ñ'));   // ñ en CP850
        $this->assertSame("\xA0", EscPos::codificar('á'));
    }
}
