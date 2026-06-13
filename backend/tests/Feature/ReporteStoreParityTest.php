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
