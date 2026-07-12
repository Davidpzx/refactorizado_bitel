<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseCOperationalParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_consulta_historial_comisiones_y_resumen_de_equipo(): void
    {
        $usuario = $this->vendedorVinculado();
        $agenteId = (int) $usuario->agente_id;

        DB::table('agentes')->where('id', $agenteId)->update(['es_gerencia' => '1']);
        DB::table('asistencias')->where('agente_id', $agenteId)->update([
            'minutos_tardanza' => 12,
        ]);
        DB::table('agentes')->insert([
            'id' => 2001,
            'dni' => '00002001',
            'nombres' => 'Agente del equipo',
            'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50',
        ]);
        DB::table('asistencias')->insert([
            'agente_id' => 2001,
            'tienda_id' => 'PUNDA50',
            'fecha' => now()->toDateString(),
            'hora_ingreso' => '08:30:00',
            'minutos_tardanza' => 30,
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => $agenteId,
            'tienda_id' => 'PUNDA50',
            'fecha' => now()->toDateString(),
        ]);
        DB::table('ventas')->insert([
            'reporte_id' => $reporteId,
            'vendedor_id' => $agenteId,
            'tipo_venta' => 'POSTPAGO',
            'comision_generada' => 18.5,
            'comision_estado' => 'ACTIVA',
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/asistencias/mi-historial?fecha_desde='.now()->toDateString().'&fecha_hasta='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('agente.id', $agenteId)
            ->assertJsonPath('agente.es_jefe', true)
            ->assertJsonPath('total_comisiones', 18.5)
            ->assertJsonCount(2, 'equipo');

        $this->assertSame(30, (int) collect($response->json('equipo'))->firstWhere('id', 2001)['tardanza_total']);
    }

    public function test_admin_actualiza_ficha_rrhh_lista_boletas_y_resetea_seguridad(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert([
            'id' => 3001,
            'dni' => '00003001',
            'nombres' => 'Agente RRHH',
            'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50',
            'hash_dispositivo' => 'hash-vigente',
            'token_emergencia' => '123456',
            'expiracion_token' => now()->addDay(),
        ]);
        DB::table('pagos_planilla')->insert([
            'agente_id' => 3001,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-15',
            'total_pagado' => 850,
            'estado' => 'PENDIENTE',
            'fecha_pago' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/agentes/3001/perfil-rrhh', [
                'telefono' => '999111222',
                'correo' => 'rrhh@example.com',
                'antecedentes_penales' => false,
                'contactos_emergencia' => [
                    ['nombre' => 'Maria', 'parentesco' => 'Madre', 'telefono' => '988777666'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.telefono', '999111222');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/agentes/3001/perfil-rrhh')
            ->assertOk()
            ->assertJsonPath('data.contactos_emergencia.0.nombre', 'Maria');

        $boletas = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/agentes/3001/boletas')
            ->assertOk();
        $this->assertSame(850.0, (float) $boletas->json('data.0.total_pagado'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/agentes/3001/reset-dispositivo')
            ->assertOk();

        $this->assertDatabaseHas('agentes', [
            'id' => 3001,
            'hash_dispositivo' => null,
            'token_emergencia' => null,
            'expiracion_token' => null,
        ]);
    }

    public function test_admin_registra_varios_imeis_y_audita_ajuste_de_stock(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', [
                'tienda_id' => 'PUNDA50',
                'producto_nombre' => 'Equipo prueba',
                'tipo' => 'EQUIPO',
                'imei_seriales' => ['IMEI-C-001', 'IMEI-C-002'],
                'precio_costo' => 300,
                'precio_minimo' => 350,
                'precio_normal' => 400,
                'cantidad' => 2,
                'estado' => 'DISPONIBLE',
            ])
            ->assertCreated()
            ->assertJsonPath('registrados', 2);

        $itemId = (int) $response->json('data.0.id');
        $this->assertDatabaseHas('inventario_tiendas', ['imei_serial' => 'IMEI-C-001', 'cantidad' => 1]);
        $this->assertDatabaseHas('inventario_tiendas', ['imei_serial' => 'IMEI-C-002', 'cantidad' => 1]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$itemId}/ajustar-stock-real", [
                'cantidad_real' => 0,
                'observacion' => 'Conteo fisico validado por gerencia',
            ])
            ->assertOk()
            ->assertJsonPath('stock_nuevo', 0);

        $this->assertDatabaseHas('historial_inventario', [
            'producto_id' => $itemId,
            'accion' => 'RESTA',
            'stock_anterior' => 1,
            'stock_nuevo' => 0,
        ]);
    }

    public function test_admin_registra_series_y_ajusta_stock_de_chips(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/chips', [
                'tienda_id' => 1,
                'tienda_origen' => 'PUNDA50',
                'tipo_chip' => 'FISICO',
                'cantidad' => 50,
                'series' => [
                    ['inicio' => '8951150001', 'fin' => '8951150050'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.series_info.0.inicio', '8951150001');

        $chipId = (int) $response->json('data.id');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/chips/{$chipId}/ajustar-stock-real", [
                'cantidad_real' => 47,
                'observacion' => 'Conteo fisico de chips validado',
            ])
            ->assertOk()
            ->assertJsonPath('stock_nuevo', 47);

        $this->assertDatabaseHas('historial_inventario', [
            'tienda_id' => '1',
            'accion' => 'RESTA',
            'stock_anterior' => 50,
            'stock_nuevo' => 47,
        ]);
    }

    public function test_confirmacion_de_lote_recibe_todos_los_items_atomicamente(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ids = [];

        foreach (['Equipo lote 1', 'Equipo lote 2'] as $index => $nombre) {
            $productoId = DB::table('inventario_tiendas')->insertGetId([
                'tienda_id' => 'PUNDA50',
                'producto_nombre' => $nombre,
                'tipo' => 'EQUIPO',
                'imei_serial' => 'LOTE-IMEI-'.$index,
                'cantidad' => 1,
                'estado' => 'TRASLADO',
                'fecha_registro' => now(),
            ]);
            $ids[] = DB::table('traslados_stock')->insertGetId([
                'producto_id' => $productoId,
                'tienda_origen' => 'PUNDA50',
                'tienda_destino' => 'PUNDA51',
                'cantidad' => 1,
                'estado' => 'PENDIENTE',
                'creado_por' => $admin->id,
                'codigo_lote' => 'LOTE-C-001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados/lote/LOTE-C-001/confirmar', [
                'observacion' => 'Recepcion completa de lote',
            ])
            ->assertOk()
            ->assertJsonPath('confirmados', $ids);

        $this->assertSame(2, DB::table('traslados_stock')->where('codigo_lote', 'LOTE-C-001')->where('estado', 'CONFIRMADO')->count());
        $this->assertSame(2, DB::table('inventario_tiendas')->where('tienda_id', 'PUNDA51')->where('estado', 'DISPONIBLE')->count());
    }

    // OPT-02: confirmarLote() consultaba/bloqueaba cada inventario de origen (SELECT+lock
    // por item) y volvia a buscar el accesorio destino por item (otro SELECT+lock por item).
    // El fix agrupa ambas cosas en un solo whereIn cada una, asi que items ACCESORIO del
    // mismo nombre en un mismo lote deben fusionarse en UNA sola fila destino con la
    // cantidad sumada, y esas dos busquedas no deben escalar con la cantidad de items del
    // lote (las N traslado->update() individuales si son inherentes: cada traslado es una
    // fila propia que hay que marcar CONFIRMADO con su propio snapshot).
    public function test_confirmar_lote_fusiona_accesorios_del_mismo_nombre_y_no_repite_busquedas_por_item(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $ids3 = $this->crearLoteAccesorios($admin, 'LOTE-ACC-003', 3);

        [$busquedasCon3Items, $response3] = $this->confirmarLoteContandoBusquedas('LOTE-ACC-003', $admin);
        $response3->assertOk()->assertJsonPath('confirmados', $ids3);

        // Los 3 items del mismo nombre deben fusionarse en una unica fila destino con la
        // cantidad acumulada (1+1+1), no en 3 filas separadas.
        $this->assertSame(1, DB::table('inventario_tiendas')
            ->where('tienda_id', 'PUNDA61')
            ->where('producto_nombre', 'Cargador Tipo C')
            ->where('estado', 'DISPONIBLE')
            ->count());
        $this->assertSame(3, (int) DB::table('inventario_tiendas')
            ->where('tienda_id', 'PUNDA61')
            ->where('producto_nombre', 'Cargador Tipo C')
            ->where('estado', 'DISPONIBLE')
            ->value('cantidad'));

        $ids6 = $this->crearLoteAccesorios($admin, 'LOTE-ACC-006', 6);

        [$busquedasCon6Items, $response6] = $this->confirmarLoteContandoBusquedas('LOTE-ACC-006', $admin);
        $response6->assertOk()->assertJsonPath('confirmados', $ids6);

        // Antes del fix esto era 1 SELECT+lock de origen + 1 SELECT+lock de accesorio POR
        // item (2*N); con el fix es 1 de cada, sin importar cuantos items tenga el lote.
        $this->assertSame(2, $busquedasCon3Items);
        $this->assertSame(
            $busquedasCon3Items,
            $busquedasCon6Items,
            'Las busquedas de origen/accesorio destino no deben escalar con el numero de items del lote.'
        );
    }

    /** @return array{0: int, 1: \Illuminate\Testing\TestResponse} */
    private function confirmarLoteContandoBusquedas(string $codigoLote, Usuario $admin): array
    {
        $busquedas = 0;
        $listener = function ($query) use (&$busquedas) {
            $sql = $query->sql;
            $esSelectInventario = str_starts_with($sql, 'select') && str_contains($sql, 'inventario_tiendas');
            $esBusquedaOrigenOAccesorio = str_contains($sql, '"estado" = ?') || str_contains($sql, '`estado` = ?');
            if ($esSelectInventario && $esBusquedaOrigenOAccesorio) {
                $busquedas++;
            }
        };

        DB::listen($listener);
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/traslados/lote/{$codigoLote}/confirmar", []);

        return [$busquedas, $response];
    }

    private function crearLoteAccesorios(Usuario $admin, string $codigoLote, int $numItems): array
    {
        $ids = [];
        for ($i = 0; $i < $numItems; $i++) {
            $productoId = DB::table('inventario_tiendas')->insertGetId([
                'tienda_id' => 'PUNDA60',
                'producto_nombre' => 'Cargador Tipo C',
                'tipo' => 'ACCESORIO',
                'cantidad' => 1,
                'estado' => 'TRASLADO',
                'fecha_registro' => now(),
            ]);
            $ids[] = DB::table('traslados_stock')->insertGetId([
                'producto_id' => $productoId,
                'tienda_origen' => 'PUNDA60',
                'tienda_destino' => 'PUNDA61',
                'cantidad' => 1,
                'estado' => 'PENDIENTE',
                'creado_por' => $admin->id,
                'codigo_lote' => $codigoLote,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
