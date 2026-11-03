<?php

namespace Tests\Feature;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\GrupoModificador;
use App\Models\Impresora;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Modificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\Terminal;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MesasTest extends TestCase
{
    private Caja $caja;
    private Terminal $mesa1;
    private Terminal $mesa2;
    private Producto $frappe;    // barra, con leche (obligatorio) y saborizantes (varios)
    private Producto $sandwich;  // cocina
    private Producto $pan;       // sin área: se entrega directo
    private array $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $this->mesa1 = Terminal::where('nombre', 'MESA 1')->first();
        $this->mesa2 = Terminal::where('nombre', 'MESA 2')->first();
        $barra = AreaPreparacion::where('nombre', 'Barra')->value('id');
        $cocina = AreaPreparacion::where('nombre', 'Cocina')->value('id');
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');

        $bebidas = Categoria::create(['nombre' => 'Bebidas', 'estado' => true, 'area_preparacion_id' => $barra]);
        $comida = Categoria::create(['nombre' => 'Comida', 'estado' => true, 'area_preparacion_id' => $cocina]);
        $panes = Categoria::create(['nombre' => 'Pan', 'estado' => true]);

        $crear = function (string $nombre, float $precio, Categoria $cat, bool $stock = false) use ($unidad) {
            $p = Producto::create(['nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => $precio,
                'costo_unitario' => round($precio / 3, 2), 'controla_stock' => $stock, 'unidad_medida_id' => $unidad]);
            $p->categorias()->attach($cat->id);
            if ($stock) {
                Inventario::create(['producto_id' => $p->id, 'stock_actual' => 100]);
            }
            return $p;
        };
        $this->frappe = $crear('Frappuccino', 3.15, $bebidas);
        $this->sandwich = $crear('Sándwich', 4.00, $comida);
        $this->pan = $crear('Pan francés', 0.15, $panes, true);

        $leche = GrupoModificador::create(['nombre' => 'Leche', 'obligatorio' => true]);
        $sabor = GrupoModificador::create(['nombre' => 'Saborizante', 'multiple' => true]);
        $this->op = [
            'entera'       => Modificador::create(['grupo_id' => $leche->id, 'nombre' => 'Entera', 'precio' => 0])->id,
            'deslactosada' => Modificador::create(['grupo_id' => $leche->id, 'nombre' => 'Deslactosada', 'precio' => 0.25])->id,
            'caramelo'     => Modificador::create(['grupo_id' => $sabor->id, 'nombre' => 'Caramelo', 'precio' => 0.50])->id,
            'vainilla'     => Modificador::create(['grupo_id' => $sabor->id, 'nombre' => 'Vainilla', 'precio' => 0.50])->id,
        ];
        $this->frappe->gruposModificadores()->attach([$leche->id => ['orden' => 1], $sabor->id => ['orden' => 2]]);

        CajaService::abrir($this->caja->id, 50, $this->crearUsuario('cajero')->id);
    }

    private function abrir(Terminal $t)
    {
        return $this->postJson("/api/mesas/terminales/{$t->id}/abrir", ['comensales' => 2])->assertOk()->json('orden');
    }

    private function agregar(int $orden, array $items, ?int $ticket = null)
    {
        return $this->postJson("/api/mesas/ordenes/{$orden}/items", ['ticket_id' => $ticket, 'items' => $items]);
    }

    private function frappe(array $mods = ['deslactosada', 'caramelo'], int $cantidad = 1): array
    {
        return ['producto_id' => $this->frappe->id, 'cantidad' => $cantidad, 'modificadores' => array_map(fn ($m) => $this->op[$m], $mods)];
    }

    private function cobrar(int $orden, array $extra, float $monto = 100)
    {
        return $this->postJson("/api/mesas/ordenes/{$orden}/cobrar", array_merge([
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => $monto]],
        ], $extra));
    }

    // ── Mapa y apertura ───────────────────────────────────────

    public function test_abrir_mesa_crea_orden_con_su_primera_cuenta(): void
    {
        $this->actuarComo('cajero');

        $orden = $this->abrir($this->mesa1);
        $this->assertSame('Ticket 1', $orden['tickets'][0]['nombre']);
        $this->assertSame($orden['id'], $this->abrir($this->mesa1)['id']); // abrir de nuevo: la misma orden

        $mapa = collect($this->getJson('/api/mesas/mapa')->assertOk()->json('terminales'))->keyBy('nombre');
        $this->assertNotNull($mapa['MESA 1']['orden']);
        $this->assertNull($mapa['MESA 2']['orden']);

        $caja = Terminal::where('tipo', 'caja')->first();
        $this->postJson("/api/mesas/terminales/{$caja->id}/abrir")->assertStatus(422);
    }

    // ── Modificadores ─────────────────────────────────────────

    public function test_modificadores_suman_al_precio_y_se_validan(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];

        $item = $this->agregar($orden, [$this->frappe(['deslactosada', 'caramelo', 'vainilla'])])->assertOk()->json('orden.tickets.0.items.0');
        $this->assertEquals(4.40, $item['precio_unitario']);   // 3.15 + 0.25 + 0.50 + 0.50
        $this->assertSame('Frappuccino (Deslactosada, Caramelo, Vainilla)', $item['descripcion']);
        $this->assertSame('Barra', $item['area']);

        $this->agregar($orden, [$this->frappe(['caramelo'])])->assertStatus(422)->assertJsonPath('message', 'Elige «Leche» para Frappuccino');
        $this->agregar($orden, [$this->frappe(['entera', 'deslactosada'])])->assertStatus(422)->assertJsonPath('message', 'En «Leche» solo se elige una opción');
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'modificadores' => [$this->op['caramelo']]]])
            ->assertStatus(422)->assertJsonPath('message', 'Uno de los extras no corresponde a Sándwich');
    }

    // ── Comandas ──────────────────────────────────────────────

    public function test_enviar_crea_una_comanda_por_area_y_lo_sin_area_va_en_la_general(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'notas' => 'Sin cebolla'],
            ['producto_id' => $this->pan->id, 'cantidad' => 3]]);

        $res = $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk()
            ->assertJsonPath('impresion.sin_impresora', true);
        $this->assertStringContainsString('enviada a', $res->json('message'));

        // Todo sale en comanda: el pan (sin área) va en la comanda general
        $comandas = Comanda::all();
        $this->assertCount(3, $comandas);
        $this->assertSame(1, $comandas->pluck('numero')->unique()->count());
        $this->assertNotNull($comandas->firstWhere('area_id', null));
        $this->assertSame('enviado', OrdenItem::where('producto_id', $this->pan->id)->value('estado'));
        $this->assertSame(0, $res->json('orden.totales.por_enviar'));

        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertStatus(422)->assertJsonPath('message', 'No hay productos nuevos para enviar');

        // Lo que se agrega después va en una comanda nueva
        $this->agregar($orden, [$this->frappe(['entera'])]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertSame(2, Comanda::distinct()->count('numero'));
    }

    public function test_cada_area_imprime_su_propio_ticket_aunque_haya_una_sola_impresora(): void
    {
        $transporte = new class implements Transporte {
            public array $enviados = [];
            public function enviar(Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = ['impresora' => $impresora->alias, 'texto' => mb_convert_encoding($bytes, 'UTF-8', 'CP850')];
            }
        };
        $this->app->instance(TransporteRed::class, $transporte);
        $impresora = fn (string $alias, string $uso) => Impresora::create(['alias' => $alias, 'conexion' => 'red', 'ip_impresora' => '192.168.1.50',
            'puerto' => 9100, 'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => $uso, 'activo' => true]);
        $tablet = $impresora('Tablet', 'ticket'); // la única impresora: la de tickets, sin categorías

        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'notas' => 'Sin cebolla'],
            ['producto_id' => $this->pan->id, 'cantidad' => 3]]);

        $res = $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk()
            ->assertJsonPath('impresion.impresas', 3)->assertJsonPath('impresion.sin_impresora', false);
        $this->assertCount(3, $res->json('comandas'));

        $tickets = collect($transporte->enviados)->keyBy(fn ($e) => str_contains($e['texto'], 'COCINA') ? 'cocina'
            : (str_contains($e['texto'], 'BARRA') ? 'barra' : 'general'));
        $this->assertCount(3, $tickets);
        $this->assertStringContainsString('Sándwich', $tickets['cocina']['texto']);
        $this->assertStringContainsString('Sin cebolla', $tickets['cocina']['texto']);
        $this->assertStringNotContainsString('Frappuccino', $tickets['cocina']['texto']);
        $this->assertStringContainsString('BARRA', $tickets['barra']['texto']);
        $this->assertStringContainsString('Frappuccino', $tickets['barra']['texto']);
        $this->assertStringNotContainsString('Sándwich', $tickets['barra']['texto']);
        $this->assertStringNotContainsString('Pan francés', $tickets['cocina']['texto'] . $tickets['barra']['texto']);
        $this->assertStringContainsString('COMANDA', $tickets['general']['texto']);
        $this->assertStringContainsString('Pan francés', $tickets['general']['texto']);
        $this->assertStringContainsString('Mesero:', $tickets['barra']['texto']);

        // Si el área tiene su impresora, va a esa
        $barra = $impresora('Barra', 'comanda');
        AreaPreparacion::where('nombre', 'Barra')->update(['impresora_id' => $barra->id]);
        $this->agregar($orden, [$this->frappe(['entera'])]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertSame('Barra', end($transporte->enviados)['impresora']);
        $this->assertCount(4, $transporte->enviados);

        // Reimprimir desde la mesa devuelve la comanda para el navegador
        $this->postJson('/api/mesas/comandas/' . Comanda::first()->id . '/imprimir')->assertOk()
            ->assertJsonPath('impreso', true)->assertJsonStructure(['comanda' => ['area', 'items']]);
        $this->assertStringContainsString('(COPIA)', end($transporte->enviados)['texto']);
        $this->assertNotNull($tablet->id);
    }

    public function test_precuenta_se_imprime_en_la_termica(): void
    {
        $transporte = new class implements Transporte {
            public array $enviados = [];
            public function enviar(Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = mb_convert_encoding($bytes, 'UTF-8', 'CP850');
            }
        };
        $this->app->instance(TransporteRed::class, $transporte);
        Impresora::create(['alias' => 'Tablet', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'activo' => true, 'predeterminada' => true]);

        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 2]]);

        $this->postJson("/api/mesas/ordenes/{$orden}/precuenta")->assertOk()->assertJsonPath('impreso', true);
        $this->assertStringContainsString('PRECUENTA', $transporte->enviados[0]);
        $this->assertStringContainsString('MESA 1', $transporte->enviados[0]);
        $this->assertStringContainsString('Frappuccino (Deslactosada, Caramelo)', $transporte->enviados[0]);
        $this->assertStringContainsString('no es comprobante de pago', $transporte->enviados[0]);
    }

    public function test_la_comanda_se_marca_entregada_desde_la_mesa(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar");

        $cocina = Comanda::whereHas('area', fn ($q) => $q->where('nombre', 'Cocina'))->first();
        $this->postJson("/api/mesas/comandas/{$cocina->id}/entregar")->assertOk()->assertJsonPath('comanda.estado', 'entregada');
        $this->assertSame('entregado', OrdenItem::where('producto_id', $this->sandwich->id)->value('estado'));
        $this->assertSame('enviado', OrdenItem::where('producto_id', $this->frappe->id)->value('estado'));
        $this->postJson("/api/mesas/comandas/{$cocina->id}/entregar")->assertStatus(422);

        // Ya no hay pantalla de cocina
        $this->getJson('/api/cocina/comandas')->assertNotFound();
    }

    // ── Cuentas separadas y cobro ─────────────────────────────

    public function test_cuentas_separadas_mover_dividir_y_cobrar_por_cuenta(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1);
        $ticket1 = $orden['tickets'][0]['id'];
        $this->agregar($orden['id'], [$this->frappe(cantidad: 2), ['producto_id' => $this->pan->id, 'cantidad' => 4]]);

        $ticket2 = $this->postJson("/api/mesas/ordenes/{$orden['id']}/tickets", ['nombre' => 'Luz'])->assertCreated()->json('ticket_id');
        $frappe = OrdenItem::where('producto_id', $this->frappe->id)->first();
        $pan = OrdenItem::where('producto_id', $this->pan->id)->first();

        $this->postJson("/api/mesas/items/{$frappe->id}/dividir", ['cantidad' => 1, 'ticket_id' => $ticket2])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/mover", ['item_ids' => [$pan->id], 'ticket_id' => $ticket2])->assertOk();

        // Ticket 1: un frappé (3.90). Luz: un frappé + 4 panes (3.90 + 0.60)
        $tickets = collect($this->getJson("/api/mesas/terminales/{$this->mesa1->id}")->json('orden.tickets'))->keyBy('nombre');
        $this->assertEquals(3.90, $tickets['Ticket 1']['total']);
        $this->assertEquals(4.50, $tickets['Luz']['total']);

        $venta = $this->cobrar($orden['id'], ['ticket_id' => $ticket1], 5)->assertCreated()->json('venta');
        $this->assertEquals(3.90, $venta['total']);
        $this->assertSame('Frappuccino (Deslactosada, Caramelo)', $venta['detalles'][0]['nombre']);
        $this->assertSame($orden['id'], Venta::find($venta['id'])->orden_id);
        $this->assertSame('abierta', Orden::find($orden['id'])->estado);

        // Ya no se puede agregar a la cuenta pagada
        $this->agregar($orden['id'], [['producto_id' => $this->pan->id, 'cantidad' => 1]], $ticket1)->assertStatus(422);

        $this->cobrar($orden['id'], ['ticket_id' => $ticket2], 10)->assertCreated()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'MESA 1 queda libre'));

        $this->assertSame('cerrada', Orden::find($orden['id'])->estado);
        $this->assertEquals(96, (float) Inventario::where('producto_id', $this->pan->id)->value('stock_actual'));
        $this->assertEquals(58.40, CajaService::resumen(TurnoCaja::first())['efectivo_esperado']);
    }

    public function test_cobrar_productos_elegidos_y_anular_reabre_la_mesa(): void
    {
        $this->actuarComo('admin');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1], ['producto_id' => $this->pan->id, 'cantidad' => 2]]);
        $pan = OrdenItem::where('producto_id', $this->pan->id)->value('id');
        $sandwich = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');

        $this->cobrar($orden, ['item_ids' => [$pan]], 1)->assertCreated()->assertJsonPath('venta.total', 0.3);
        $this->cobrar($orden, ['item_ids' => [$pan]])->assertStatus(422)->assertJsonPath('message', 'No hay productos por cobrar en esa cuenta');

        $venta = $this->cobrar($orden, ['item_ids' => [$sandwich]], 5)->json('venta');
        $this->assertSame('cerrada', Orden::find($orden)->estado);

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Se cobró a la mesa equivocada'])->assertOk();
        $this->assertSame('abierta', Orden::find($orden)->estado);
        $this->assertNull(OrdenItem::find($sandwich)->venta_id);
    }

    public function test_reintento_de_cobro_no_duplica(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $uuid = (string) Str::uuid();

        $this->cobrar($orden, ['uuid' => $uuid], 5)->assertCreated();
        $this->cobrar($orden, ['uuid' => $uuid], 5)->assertOk();
        $this->assertSame(1, Venta::count());
    }

    // ── Quitar, cerrar y trasladar ────────────────────────────

    public function test_quitar_producto_enviado_solo_lo_hace_el_administrador(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Eduardo']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->pan->id, 'cantidad' => 1], ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $pan = OrdenItem::where('producto_id', $this->pan->id)->value('id');

        $this->postJson("/api/mesas/items/{$pan}/quitar")->assertOk();          // pendiente: se borra
        $this->assertNull(OrdenItem::find($pan));

        $this->postJson("/api/mesas/ordenes/{$orden}/enviar");
        $sandwich = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'El cliente cambió de opinión'])->assertStatus(403);
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'El cliente cambió de opinión'])->assertOk();

        $this->assertSame('cancelado', OrdenItem::find($sandwich)->estado);
        $this->assertSame('cancelada', Comanda::first()->estado);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cancelar_item']);
    }

    public function test_liberar_mesa_con_pendientes_solo_lo_hace_el_administrador(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Eduardo']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);

        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar")->assertStatus(422);
        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar", ['motivo' => 'Se fueron sin consumir'])->assertStatus(403);
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar", ['motivo' => 'Se fueron sin consumir'])->assertOk();
        $this->assertSame('cancelada', Orden::find($orden)->estado);

        // Mesa vacía: se libera sin autorización
        $vacia = $this->abrir($this->mesa2)['id'];
        $this->postJson("/api/mesas/ordenes/{$vacia}/cerrar")->assertOk();
    }

    public function test_trasladar_a_mesa_libre(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->abrir($this->mesa2);
        $mesa3 = Terminal::where('nombre', 'MESA 3')->first();

        $this->postJson("/api/mesas/ordenes/{$orden}/trasladar", ['terminal_id' => $this->mesa2->id])->assertStatus(422)
            ->assertJsonPath('message', 'MESA 2 está ocupada');
        $this->postJson("/api/mesas/ordenes/{$orden}/trasladar", ['terminal_id' => $mesa3->id])->assertOk()
            ->assertJsonPath('orden.terminal.nombre', 'MESA 3');
    }

    // ── Configuración y permisos ──────────────────────────────

    public function test_configurar_modificadores_areas_y_terminales(): void
    {
        $this->actuarComo('admin');

        $this->postJson('/api/mesas/config/modificadores', [
            'nombre' => 'Tamaño', 'obligatorio' => true,
            'opciones' => [['nombre' => 'Mediano', 'precio' => 0], ['nombre' => 'Grande', 'precio' => 0.75]],
            'productos' => [$this->frappe->id],
        ])->assertCreated();
        $catalogo = $this->getJson('/api/mesas/catalogo')->assertOk();
        $this->assertCount(3, collect($catalogo->json('productos'))->firstWhere('id', $this->frappe->id)['grupos']);

        $pan = Categoria::where('nombre', 'Pan')->first();
        $this->putJson('/api/mesas/config/categorias', ['asignaciones' => [['categoria_id' => $pan->id, 'area_id' => AreaPreparacion::where('nombre', 'Cocina')->value('id')]]])->assertOk();
        $this->assertNotNull($pan->fresh()->area_preparacion_id);

        $this->postJson('/api/mesas/config/terminales', ['nombre' => 'TERRAZA 1', 'tipo' => 'mesa', 'zona' => 'Terraza', 'capacidad' => 6])->assertCreated();
        $this->getJson('/api/mesas/config/terminales')->assertJsonFragment(['zonas' => ['Mostrador', 'Salón', 'Terraza']]);

        $this->actuarComo('cajero');
        $this->getJson('/api/mesas/config/terminales')->assertStatus(403);
    }
}
