<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseDPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 8, 0, 0, 'America/Lima'));
        config(['attendance.qr_secret' => 'phase-d-qr-secret']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_qr_se_genera_en_backend_con_secreto_dedicado(): void
    {
        $this->crearTienda('T01');
        $usuario = Usuario::factory()->vendedor('T01')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/attendance/qr-stream/T01')
            ->assertOk()
            ->assertJsonPath('tienda_id', 'T01');

        $token = $response->json('token');
        [, $tienda, $bloque, $hmac] = explode('|', $token);
        $esperado = substr(hash_hmac('sha256', "AST|{$tienda}|{$bloque}", 'phase-d-qr-secret'), 0, 16);

        $this->assertSame($esperado, $hmac);
        $this->assertStringStartsWith('data:image/svg+xml', $response->json('image_data_uri'));
        $this->assertStringNotContainsString('qrserver.com', $response->getContent());
    }

    public function test_qr_limita_escaneos_simultaneos_por_tienda(): void
    {
        $this->crearTienda('T01');
        DB::table('agentes')->insert([
            'id' => 1,
            'dni' => '12345678',
            'nombres' => 'Agente QR',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
            'hash_dispositivo' => 'device-123',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '18:00:00',
            'hora_ref_inicio' => '12:00:00',
            'hora_ref_fin' => '13:00:00',
        ]);
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => now()->toDateString(),
            'hora_ingreso' => '07:59:55',
            'metodo_marcacion' => 'QR',
        ]);

        $bloque = (int) floor(now()->timestamp / 5);
        $hmac = substr(hash_hmac('sha256', "AST|T01|{$bloque}", 'phase-d-qr-secret'), 0, 16);

        $this->postJson('/api/v1/attendance/mark-qr', [
            'dni' => '12345678',
            'tipo' => 'inicio_refrigerio',
            'tienda_id' => 'T01',
            'qr_token' => "AST|T01|{$bloque}|{$hmac}",
            'device_id' => 'device-123',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'QR_COLLISION');
    }

    public function test_chips_se_descuentan_y_reponen_en_sus_lotes_originales(): void
    {
        $tiendaId = $this->crearTienda('PUNDA50');
        $usuario = $this->vendedorVinculado();
        $loteExacto = DB::table('inventario_chips')->insertGetId([
            'tienda_id' => $tiendaId,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip' => 'FÍSICO',
            'stock_actual' => 1,
        ]);
        $loteLegacy = DB::table('inventario_chips')->insertGetId([
            'tienda_id' => $tiendaId,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip' => 'FÍSICO',
            'stock_actual' => 2,
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payloadLinea($usuario, 3))
            ->assertCreated();

        $reporteId = $response->json('id');
        $ventaId = DB::table('ventas')->where('reporte_id', $reporteId)->value('id');
        $this->assertDatabaseHas('venta_chip_movimientos', [
            'venta_id' => $ventaId,
            'inventario_chip_id' => $loteExacto,
            'cantidad' => 1,
        ]);
        $this->assertDatabaseHas('venta_chip_movimientos', [
            'venta_id' => $ventaId,
            'inventario_chip_id' => $loteLegacy,
            'cantidad' => 2,
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->deleteJson("/api/v1/reportes/{$reporteId}")
            ->assertNoContent();

        $this->assertSame(1, (int) DB::table('inventario_chips')->where('id', $loteExacto)->value('stock_actual'));
        $this->assertSame(2, (int) DB::table('inventario_chips')->where('id', $loteLegacy)->value('stock_actual'));
        $this->assertDatabaseMissing('venta_chip_movimientos', ['venta_id' => $ventaId]);
    }

    public function test_reporte_falla_completo_si_no_hay_chips_suficientes(): void
    {
        $tiendaId = $this->crearTienda('PUNDA50');
        $usuario = $this->vendedorVinculado();
        DB::table('inventario_chips')->insert([
            'tienda_id' => $tiendaId,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip' => 'FÍSICO',
            'stock_actual' => 1,
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payloadLinea($usuario, 2))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_GUARD');

        $this->assertDatabaseCount('reportes', 0);
        $this->assertSame(1, (int) DB::table('inventario_chips')->value('stock_actual'));
    }

    public function test_recalculo_respeta_upgrade_migracion_esim_y_prepago(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('comisiones_planes')->insert([
            [
                'tipo_servicio' => 'POSTPAGO',
                'nombre_plan' => 'Plan Base',
                'tipo_alta' => 'LN',
                'fee_monto' => 49.90,
                'comision_dni_n' => 30,
                'comision_dni_n3' => 0,
                'comision_ext_n' => 35,
                'comision_ext_n3' => 0,
            ],
            [
                'tipo_servicio' => 'PREPAGO',
                'nombre_plan' => 'Prepago 10',
                'tipo_alta' => 'LN',
                'fee_monto' => 0,
                'comision_dni_n' => 10,
                'comision_dni_n3' => 0,
                'comision_ext_n' => 10,
                'comision_ext_n3' => 0,
            ],
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-15',
            'estado' => 'enviado',
        ]);

        $casos = [
            ['POSTPAGO', 'Plan Base', ['es_upgrade' => 1, 'plan_anterior' => 29.90], 20],
            ['POSTPAGO', 'Plan Base', ['es_migracion' => 1], 30],
            ['POSTPAGO', 'Plan Base', ['es_esim' => 1], 30],
            ['POSTPAGO', 'Plan Base', [], 29],
            ['PREPAGO', 'Prepago 10', ['cobrado_unitario' => 10], 9],
        ];

        foreach ($casos as [$tipoVenta, $plan, $extra, $esperado]) {
            $ventaId = DB::table('ventas')->insertGetId([
                'reporte_id' => $reporteId,
                'vendedor_id' => 1,
                'tipo_venta' => $tipoVenta,
                'monto_total' => 30,
                'comision_generada' => 0,
                'comision_estado' => 'ACTIVA',
                'es_remate' => false,
                'es_extranjero' => false,
            ]);
            $lineaId = DB::table('venta_lineas')->insertGetId(array_merge([
                'venta_id' => $ventaId,
                'plan_nombre_snap' => $plan,
                'tipo_alta' => 'LN',
                'cantidad' => 1,
                'cobrado_unitario' => 30,
                'comision_unitaria' => 0,
                'es_migracion' => false,
                'es_upgrade' => false,
                'es_esim' => false,
                'plan_anterior' => null,
            ], $extra));

            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/comisiones-planes/recalcular', [
                    'fecha_desde' => '2026-06-15',
                    'fecha_hasta' => '2026-06-15',
                ])
                ->assertOk();

            $this->assertSame((float) $esperado, (float) DB::table('venta_lineas')->where('id', $lineaId)->value('comision_unitaria'));
        }
    }

    public function test_exports_operativos_son_xlsx(): void
    {
        $admin = Usuario::factory()->admin()->create();

        foreach ([
            '/api/v1/asistencias/exportar?fecha_desde=2026-06-01&fecha_hasta=2026-06-15',
            '/api/v1/inventario/exportar-kardex',
            '/api/v1/estadisticas/exportar?fecha_desde=2026-06-01&fecha_hasta=2026-06-15',
        ] as $url) {
            $this->actingAs($admin, 'sanctum')
                ->get($url)
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    private function crearTienda(string $codigo): int
    {
        return DB::table('tiendas')->insertGetId([
            'codigo' => $codigo,
            'nombre' => "Tienda {$codigo}",
        ]);
    }

    private function payloadLinea(Usuario $usuario, int $cantidad): array
    {
        return [
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => now()->toDateString(),
            'caja_inicial' => 0,
            'yape' => 0,
            'bipay' => 0,
            'transferencia' => 0,
            'recarga_bipay' => 0,
            'pago_servicio' => 0,
            'pago_krece' => 0,
            'pago_payjoy' => 0,
            'tickets_tusamy' => 0,
            'retiro_bipay' => 0,
            'efectivo_entregado' => 0,
            'total_salidas' => 0,
            'ventas' => [[
                'vendedor_id' => $usuario->agente_id,
                'tipo_venta' => 'PREPAGO',
                'monto_total' => 0,
                'plan_nombre' => 'Prepago',
                'tipo_alta' => 'LN',
                'cantidad' => $cantidad,
                'cobrado_unitario' => 0,
            ]],
        ];
    }
}
