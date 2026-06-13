<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReporteStoreParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persiste_apoyo_comisiones_e_ingresos_fijos_sin_cambiar_efectivo_esperado(): void
    {
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($usuario, [
                'caja_inicial' => 50,
                'total_salidas' => 5,
                'recarga_bipay' => 1,
                'pago_servicio' => 2,
                'pago_krece' => 3,
                'tickets_tusamy' => 4,
                'efectivo_entregado' => 77,
                'ventas' => [
                    [
                        'tipo_venta' => 'POSTPAGO',
                        'monto_total' => 10,
                        'efectivo_inicial' => 10,
                        'plan_nombre' => 'Plan 29.90',
                        'cantidad' => 2,
                        'cobrado_unitario' => 5,
                        'comision_unitaria' => 8.5,
                        'es_esim' => true,
                    ],
                    [
                        'tipo_venta' => 'APOYO',
                        'monto_total' => 15,
                        'efectivo_inicial' => 15,
                        'tienda_destino' => 'PUNDA11',
                        'plan_nombre' => 'Prepago apoyo',
                        'cantidad' => 3,
                        'cobrado_unitario' => 5,
                        'comision_unitaria' => 2,
                    ],
                    [
                        'tipo_venta' => 'OTROS_FLUJO',
                        'monto_total' => 7,
                        'efectivo_inicial' => 7,
                    ],
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('total_calculado', '42.00')
            ->assertJsonPath('efectivo_esperado', '77.00')
            ->assertJsonPath('diferencia', '0.00');

        $reporteId = $response->json('id');
        $postpago = DB::table('ventas')
            ->where('reporte_id', $reporteId)
            ->where('tipo_venta', 'POSTPAGO')
            ->first();
        $apoyo = DB::table('ventas')
            ->where('reporte_id', $reporteId)
            ->where('tipo_venta', 'APOYO')
            ->first();

        $this->assertSame(17.0, (float) $postpago->comision_generada);
        $this->assertSame(6.0, (float) $apoyo->comision_generada);
        $this->assertSame('PUNDA11', $apoyo->tienda_destino);

        $this->assertDatabaseHas('venta_lineas', [
            'venta_id' => $postpago->id,
            'cantidad' => 2,
            'comision_unitaria' => 8.5,
            'es_esim' => 1,
        ]);
        $this->assertDatabaseHas('venta_lineas', [
            'venta_id' => $apoyo->id,
            'cantidad' => 3,
            'comision_unitaria' => 2,
        ]);
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
