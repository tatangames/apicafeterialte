<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recetas que se descuentan al vender (limonada, platos, servicios), consumo interno
 * a $0.00, bajas manuales autorizadas y lista de compras del cierre de turno.
 */
class RecetasVentaTest extends TestCase
{
    private Caja $caja;
    private TurnoCaja $turno;
    private array $u;
    private Producto $limon;   // unidades, mínimo 20
    private Producto $azucar;  // gramos
    private Producto $aceite;  // se lleva en mililitros y se compra por galón
    private Producto $huevo;   // unidades
    private Producto $limonada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);
        $this->u = UnidadesMedida::pluck('id', 'nombre')->all();
        $this->caja = Caja::first();
        $this->turno = CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);

        $this->limon = $this->insumo('Limón', 'Unidad', 0.05, 30, 20);
        $this->azucar = $this->insumo('Azúcar', 'Gramo', 0.002, 5000, 1000, ['permite_fraccion' => true]);
        $this->aceite = $this->insumo('Aceite', 'Mililitro', 0.0032, 4 * 3785.4118, 500, ['permite_fraccion' => true]);
        $this->huevo = $this->insumo('Huevo', 'Unidad', 0.15, 60, 0);
        $this->limonada = $this->plato('Limonada', 1.00);
    }

    private function insumo(string $nombre, string $unidad, float $costo, float $stock, float $minimo, array $extra = []): Producto
    {
        $p = Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'materia_prima', 'se_vende' => false,
            'controla_stock' => true, 'costo_unitario' => $costo, 'unidad_medida_id' => $this->u[$unidad],
        ], $extra));
        Inventario::create(['producto_id' => $p->id, 'stock_actual' => $stock, 'stock_minimo' => $minimo]);
        return $p;
    }

    // Lo que se vende sin existencia propia: sale de su receta
    private function plato(string $nombre, float $precio, array $extra = []): Producto
    {
        return Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'servicio', 'clase' => 'producto_terminado', 'se_vende' => true,
            'controla_stock' => false, 'precio_venta' => $precio, 'unidad_medida_id' => $this->u['Unidad'],
        ], $extra));
    }

    private function receta(Producto $producto, array $ingredientes, float $rendimiento = 1)
    {
        return $this->postJson('/api/produccion/recetas', [
            'producto_id' => $producto->id, 'rendimiento' => $rendimiento,
            'ingredientes' => array_map(fn ($i) => [
                'producto_id' => $i[0]->id, 'unidad_medida_id' => $this->u[$i[1]], 'cantidad' => $i[2],
            ], $ingredientes),
        ]);
    }

    private function vender(array $items, ?float $efectivo = null)
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid'    => (string) Str::uuid(),
            'caja_id' => $this->caja->id,
            'items'   => array_map(fn ($i) => ['producto_id' => $i[0]->id, 'cantidad' => $i[1]], $items),
            'pagos'   => $efectivo === null ? [] : [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => $efectivo]],
        ]);
    }

    private function stock(Producto $p): float
    {
        return (float) Inventario::where('producto_id', $p->id)->value('stock_actual');
    }

    public function test_vender_con_receta_descuenta_los_insumos_y_costea_la_venta(): void
    {
        $this->actuarComo('admin');
        // 4 limones y 30 g de azúcar por limonada
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4], [$this->azucar, 'Gramo', 30]])
            ->assertCreated()->assertJsonPath('receta.uso', 'venta');

        $this->vender([[$this->limonada, 3]], 3)->assertCreated();

        $this->assertEquals(18, $this->stock($this->limon));
        $this->assertEquals(4910, $this->stock($this->azucar));
        // 12 x 0.05 + 90 x 0.002 = 0.78
        $this->assertEquals(0.78, (float) Venta::first()->costo_total);
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $this->limon->id, 'tipo' => 'venta', 'cantidad' => 12, 'motivo' => 'Venta T-000001: Limonada',
        ]);

        // Una receta de venta no se produce
        $this->postJson('/api/produccion', ['uuid' => (string) Str::uuid(), 'receta_id' => \App\Models\Receta::first()->id, 'cantidad_producida' => 1])
            ->assertStatus(422)->assertJsonPath('message', 'La receta de Limonada se descuenta al vender: no se produce');
    }

    public function test_gasto_en_mililitros_de_un_insumo_comprado_por_galon(): void
    {
        $this->actuarComo('admin');
        $huevos = $this->plato('Huevos estrellados', 2.50);
        $this->receta($huevos, [[$this->aceite, 'Mililitro', 2], [$this->huevo, 'Unidad', 2]])->assertCreated();

        $this->vender([[$huevos, 10]], 25)->assertCreated();

        // 4 galones = 15,141.65 ml: salen 20 ml
        $this->assertEqualsWithDelta(15141.6472 - 20, $this->stock($this->aceite), 0.0001);
        $this->assertEquals(40, $this->stock($this->huevo));
    }

    public function test_servicio_con_insumos_y_anulacion_que_los_devuelve(): void
    {
        $this->actuarComo('admin');
        $pastel = $this->insumo('Pastel pequeño', 'Unidad', 4, 3, 0);
        $gorro = $this->insumo('Gorro de cumpleaños', 'Unidad', 0.25, 10, 0);
        $mananitas = $this->plato('Cantar las mañanitas', 3.00);
        $this->receta($mananitas, [[$pastel, 'Unidad', 1], [$gorro, 'Unidad', 1]])->assertCreated();

        $id = $this->vender([[$mananitas, 1]], 3)->assertCreated()->assertJsonPath('venta.total', 3)->json('venta.id');
        $this->assertEquals(2, $this->stock($pastel));
        $this->assertEquals(9, $this->stock($gorro));

        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'No se cantó'])->assertOk();
        $this->assertEquals(3, $this->stock($pastel));
        $this->assertEquals(10, $this->stock($gorro));
    }

    public function test_insumo_insuficiente_rechaza_la_venta_sin_dejar_rastro(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->azucar, 'Gramo', 30], [$this->limon, 'Unidad', 4]])->assertCreated();

        $this->vender([[$this->limonada, 8]], 8)->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para Limón: disponible 30, solicitado 32');

        $this->assertEquals(5000, $this->stock($this->azucar));
        $this->assertSame(0, Venta::count());
    }

    public function test_receta_de_venta_solo_lleva_insumos_con_inventario(): void
    {
        $this->actuarComo('admin');
        $hielo = $this->plato('Hielo', 0);

        $this->receta($this->limonada, [[$hielo, 'Unidad', 1]])->assertStatus(422)
            ->assertJsonPath('message', 'Hielo no lleva inventario: no se puede descontar al vender Limonada');
    }

    public function test_consumo_interno_no_cobra_y_solo_descuenta_inventario(): void
    {
        $this->actuarComo('admin');
        $cena = $this->postJson('/api/productos/registro', [
            'nombre' => 'Cena típica del personal', 'clase' => 'producto_terminado', 'se_vende' => false,
            'consumo_interno' => true, 'controla_stock' => false, 'permite_fraccion' => false,
            'costo_unitario' => 0, 'precio_venta' => 5, 'unidad_medida_id' => $this->u['Unidad'],
        ])->assertSuccessful();
        $cena = Producto::where('nombre', 'Cena típica del personal')->first();
        $this->assertTrue($cena->consumo_interno && $cena->se_vende);
        $this->assertEquals(0, (float) $cena->precio_venta);

        $this->receta($cena, [[$this->huevo, 'Unidad', 2]])->assertCreated();
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4]])->assertCreated();

        $this->vender([[$cena, 3]])->assertCreated()->assertJsonPath('venta.total', 0);
        $this->vender([[$this->limonada, 1]], 1)->assertCreated();

        $this->assertEquals(54, $this->stock($this->huevo));
        $this->assertSame(1, MovimientoCaja::where('tipo', 'venta')->count()); // solo la limonada
        $this->assertDatabaseHas('movimientos_inventario', ['producto_id' => $this->huevo->id, 'tipo' => 'consumo_interno', 'cantidad' => 6]);
        $this->assertDatabaseHas('ventas_detalle', ['producto_id' => $cena->id, 'consumo_interno' => true, 'subtotal' => 0]);

        $consumo = collect(CajaService::resumen($this->turno->fresh())['consumo'])->keyBy('producto');
        $this->assertEquals(6, $consumo['Huevo']['consumo_interno']);
        $this->assertEquals(0, $consumo['Huevo']['vendido']);
        $this->assertEquals(4, $consumo['Limón']['vendido']);
    }

    public function test_bajar_a_mano_un_insumo_de_receta_solo_lo_hace_el_administrador(): void
    {
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->aceite, 'Mililitro', 5]])->assertCreated();

        $admin = $this->crearUsuario('admin');
        $this->actuarComo('encargado_inventario');
        $ajuste = fn (Producto $p, float $conteo, array $extra = []) => $this->postJson('/api/inventario/entradas/registrar', array_merge([
            'fecha' => today()->toDateString(), 'tipo' => 'ajuste', 'descripcion' => 'Se acabó',
            'items' => [['producto_id' => $p->id, 'cantidad' => $conteo]],
        ], $extra));

        // "Ya se acabó el aceite": el aceite solo baja con los platos
        $ajuste($this->aceite, 0)->assertStatus(403)->assertJsonPath('permiso', 'inventario.autorizar_baja');
        $this->assertEqualsWithDelta(15141.6472, $this->stock($this->aceite), 0.0001);

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $ajuste($this->aceite, 0)->assertSuccessful();
        $this->assertEquals(0, $this->stock($this->aceite));
        $this->assertNotNull(\App\Models\EntradaInventario::latest('id')->first()->autorizado_por);

        // Subir un insumo o bajar algo que no está en recetas no lo pide
        $ajuste($this->aceite, 2)->assertSuccessful();
        $ajuste($this->huevo, 10)->assertSuccessful();
    }

    public function test_cierre_de_turno_deja_la_lista_de_compras_para_el_siguiente(): void
    {
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4]])->assertCreated();
        $this->vender([[$this->limonada, 3]], 3)->assertCreated(); // limón: 30 → 18, mínimo 20

        $cerrado = CajaService::cerrar($this->turno, null, 23, auth()->id());
        $porComprar = collect($cerrado->resumen['por_comprar'])->keyBy('producto');

        $this->assertTrue($porComprar->has('Limón'));
        $this->assertEquals(2, $porComprar['Limón']['faltante']);
        $this->assertFalse($porComprar->has('Azúcar')); // está arriba de su mínimo
        $this->assertEquals(12, collect($cerrado->resumen['consumo'])->firstWhere('producto', 'Limón')['vendido']);

        // Quien recibe la caja ve qué comprar antes de abrir
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertOk()
            ->assertJsonPath('turno', null)
            ->assertJsonPath('turno_anterior.id', $cerrado->id)
            ->assertJsonPath('por_comprar.0.producto', 'Limón');
    }

    public function test_frijoles_crudos_en_libras_rinden_porciones_cocidas(): void
    {
        $this->actuarComo('admin');
        $crudo = $this->insumo('Frijol crudo', 'Libra', 0.90, 10, 0, ['permite_fraccion' => true]);
        $cocido = $this->insumo('Frijol cocido', 'Porción', 0, 0, 0, ['clase' => 'producto_terminado']);

        // 1 lb rinde 20 porciones
        $id = $this->receta($cocido, [[$crudo, 'Libra', 1]], 20)->assertCreated()
            ->assertJsonPath('receta.uso', 'produccion')->json('receta.id');

        $this->postJson('/api/produccion', [
            'uuid' => (string) Str::uuid(), 'receta_id' => $id, 'cantidad_planificada' => 80, 'cantidad_producida' => 80,
        ])->assertCreated();

        $this->assertEquals(6, $this->stock($crudo));
        $this->assertEquals(80, $this->stock($cocido));
    }
}
