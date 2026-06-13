<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReporteStoreParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_calcula_comision_server_side_segun_tramos_legacy(): void
    {
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        // Plan sembrado: base S/15 (la comisión NO se confía al cliente).
        DB::table('comisiones_planes')->insert([
            'tipo_servicio'   => 'POSTPAGO',
            'nombre_plan'     => 'Plan Normal',
            'tipo_alta'       => 'MNP',
            'fee_monto'       => 0,
            'comision_dni_n'  => 15,
            'comision_dni_n3' => 15,
            'comision_ext_n'  => 15,
            'comision_ext_n3' => 15,
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($usuario, [
                'caja_inicial'       => 50,
                'total_salidas'      => 5,
                'efectivo_entregado' => 95,
                'ventas' => [
                    // Postpago normal: base 15 − chip 1 = 14 (ignora comision_unitaria=999 del cliente).
                    ['tipo_venta' => 'POSTPAGO', 'monto_total' => 30, 'efectivo_inicial' => 30,
                     'plan_nombre' => 'Plan Normal', 'cantidad' => 1, 'cobrado_unitario' => 30, 'comision_unitaria' => 999],
                    // Postpago remate (cobrado 10 < 20): 0.
                    ['tipo_venta' => 'POSTPAGO', 'monto_total' => 10, 'efectivo_inicial' => 10,
                     'plan_nombre' => 'Plan Normal', 'cantidad' => 1, 'cobrado_unitario' => 10, 'comision_unitaria' => 999],
                    // Apoyo PAQUETE: 7.5% de 100 = 7.5.
                    ['tipo_venta' => 'APOYO', 'monto_total' => 100, 'efectivo_inicial' => 100, 'subtipo' => 'PAQUETE',
                     'tienda_destino' => 'PUNDA11', 'cantidad' => 1, 'cobrado_unitario' => 100, 'comision_unitaria' => 999],
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('total_calculado', '140.00')   // suma de monto_total (30+10+100)
            // B3: esperado = total_sistema(140) − no_fisico(0) − salidas(5) = 135 (NO suma caja_inicial 50)
            ->assertJsonPath('efectivo_esperado', '135.00')
            ->assertJsonPath('diferencia', '-40.00');       // entregado 95 − esperado 135

        $reporteId = $response->json('id');
        $rows = DB::table('ventas')->where('reporte_id', $reporteId)->orderBy('id')->get();

        $this->assertSame(14.0, (float) $rows[0]->comision_generada); // postpago normal
        $this->assertSame(0.0,  (float) $rows[1]->comision_generada); // remate < S/20
        $this->assertSame(7.5,  (float) $rows[2]->comision_generada); // apoyo paquete 7.5%
        $this->assertSame('PUNDA11', $rows[2]->tienda_destino);
    }

    public function test_store_mantiene_guardia_anti_duplicado(): void
    {
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();
        $payload = $this->payload($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $payload)
            ->assertCreated();

        $this->postJson('/api/v1/reportes', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DUPLICATE_REPORT');

        $this->assertDatabaseCount('reportes', 1);
    }

    public function test_equipo_cuotas_pendiente_y_desembolso_libera_comision(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $admin    = Usuario::factory()->admin()->create();

        // Equipo a CUOTAS sin inventario_tienda_id (salta la guardia de stock B1).
        $this->actingAs($vendedor, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($vendedor, [
                'fecha'              => now()->toDateString(),
                'efectivo_entregado' => 300,
                'ventas' => [[
                    'tipo_venta' => 'EQUIPO', 'monto_total' => 300, 'efectivo_inicial' => 300,
                    'producto_nombre' => 'iPhone 15', 'tipo_pago' => 'CUOTAS', 'financiera' => 'KREDITO',
                    'por_cobrar_financiera' => 1200, 'precio_venta' => 1500, 'costo_snap' => 1000,
                ]],
            ]))->assertCreated();

        $venta = DB::table('ventas')->where('vendedor_id', $vendedor->id)->where('tipo_venta', 'EQUIPO')->first();
        $this->assertSame('PENDIENTE', $venta->comision_estado);     // diferida hasta el desembolso
        $this->assertSame(0.0, (float) $venta->comision_generada);

        // D1 — el panel de financieras (esquema normalizado) lo lista como pendiente.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/financieras?mes=' . now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('totales.count_pendiente', 1)
            ->assertJsonPath('data.0.detalle.producto_nombre', 'iPhone 15');

        // D2 — confirmar desembolso → APROBADA + comisión del agente liberada (S/5).
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$venta->id}/confirmar-desembolso")
            ->assertOk()
            ->assertJsonPath('success', true);

        $after = DB::table('ventas')->where('id', $venta->id)->first();
        $this->assertSame('APROBADA', $after->comision_estado);
        $this->assertSame(5.0, (float) $after->comision_generada);
    }

    public function test_reprocesar_revierte_stock_y_recrea_ventas(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();

        $invId = DB::table('inventario_tiendas')->insertGetId([
            'tienda_id' => 'PUNDA50', 'tipo' => 'EQUIPO', 'producto_nombre' => 'iPhone 15',
            'cantidad' => 1, 'estado' => 'DISPONIBLE',
            'precio_costo' => 1000, 'precio_minimo' => 1200, 'precio_normal' => 1500,
            'fecha_registro' => now(),
        ]);

        // Store: vende el equipo (stock 1 → 0).
        $resp = $this->actingAs($vendedor, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($vendedor, [
                'fecha' => now()->toDateString(), 'efectivo_entregado' => 1500,
                'ventas' => [[
                    'tipo_venta' => 'EQUIPO', 'monto_total' => 1500, 'efectivo_inicial' => 1500,
                    'producto_nombre' => 'iPhone 15', 'tipo_pago' => 'CONTADO',
                    'inventario_tienda_id' => $invId, 'precio_venta' => 1500, 'costo_snap' => 1000,
                ]],
            ]))->assertCreated();
        $reporteId = $resp->json('id');

        $this->assertSame(0, (int) DB::table('inventario_tiendas')->where('id', $invId)->value('cantidad'));
        $this->assertSame(1, DB::table('ventas')->where('reporte_id', $reporteId)->count());

        // Autorizar edición y reprocesar SIN el equipo → el stock debe volver a 1.
        DB::table('reportes')->where('id', $reporteId)->update(['estado_edicion' => 'APROBADO']);

        $this->actingAs($vendedor, 'sanctum')
            ->putJson("/api/v1/reportes/{$reporteId}/reprocesar", $this->payload($vendedor, [
                'fecha' => now()->toDateString(), 'efectivo_entregado' => 0, 'ventas' => [],
            ]))->assertOk();

        $this->assertSame(1, (int) DB::table('inventario_tiendas')->where('id', $invId)->value('cantidad'));
        $this->assertSame('DISPONIBLE', DB::table('inventario_tiendas')->where('id', $invId)->value('estado'));
        $this->assertSame(0, DB::table('ventas')->where('reporte_id', $reporteId)->count());
        $this->assertSame('CERRADO', DB::table('reportes')->where('id', $reporteId)->value('estado_edicion'));
    }

    public function test_edicion_ligera_recalcula_diferencia_y_cierra_edicion(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();

        $resp = $this->actingAs($vendedor, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($vendedor, [
                'fecha' => now()->toDateString(), 'caja_inicial' => 0, 'efectivo_entregado' => 100,
                'ventas' => [['tipo_venta' => 'OTROS_FLUJO', 'monto_total' => 100, 'efectivo_inicial' => 100]],
            ]))->assertCreated();
        $rid = $resp->json('id'); // esperado = 100 − 0 − 0 = 100

        DB::table('reportes')->where('id', $rid)->update(['estado_edicion' => 'APROBADO', 'estado' => 'enviado']);

        $this->actingAs($vendedor, 'sanctum')
            ->putJson("/api/v1/reportes/{$rid}", ['efectivo_entregado' => 80, 'motivo_edicion' => 'corrige'])
            ->assertOk()
            ->assertJsonPath('diferencia', '-20.00')      // 80 − 100
            ->assertJsonPath('estado_edicion', 'CERRADO');
    }

    private function payload(Usuario $usuario, array $overrides = []): array
    {
        return array_replace([
            'agente_id' => $usuario->id,
            'tienda_id' => 'PUNDA50',
            'usuario_id' => $usuario->id,
            'fecha' => '2026-06-13',
            'caja_inicial' => 0,
            'yape' => 0,
            'bipay' => 0,
            'transferencia' => 0,
            'recarga_bipay' => 0,
            'pago_servicio' => 0,
            'pago_krece' => 0,
            'tickets_tusamy' => 0,
            'retiro_bipay' => 0,
            'efectivo_entregado' => 0,
            'total_salidas' => 0,
            'ventas' => [],
        ], $overrides);
    }
}
