<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gap P2 (paridad legacy reportes/procesar_edicion.php → flag es_restauracion):
 * cuando una edición devuelve una venta al vendedor original (revierte un "robo de
 * comisión" registrado en la primera alerta 'edicion_critica'), el legacy audita ese
 * movimiento como 'edicion_restaurada' en vez de 'edicion_critica'. Este test verifica
 * que el refactor porta ese flag sobre su flujo de reprocesar existente.
 */
class ReporteRestauracionComisionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Usuario $usuario, array $overrides = []): array
    {
        $payload = array_replace([
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'usuario_id' => $usuario->id,
            'fecha' => now()->toDateString(),
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

        $payload['ventas'] = array_map(
            fn (array $venta) => array_replace(['vendedor_id' => $usuario->agente_id], $venta),
            $payload['ventas']
        );

        return $payload;
    }

    public function test_restaurar_vendedor_original_se_audita_como_edicion_restaurada(): void
    {
        $cajero = $this->vendedorVinculado('PUNDA50');
        $admin  = Usuario::factory()->admin()->create();
        $otroAgenteId = 2200;

        DB::table('agentes')->insert([
            'id' => $otroAgenteId, 'nombres' => 'Segundo vendedor', 'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50', 'dni' => '00002200',
        ]);

        // 1) Reporte con una venta a nombre del cajero original.
        $resp = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($cajero, [
                'efectivo_entregado' => 40,
                'ventas' => [[
                    'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'Accesorio manual',
                    'monto_total' => 40, 'efectivo_inicial' => 40, 'vendedor_id' => $cajero->agente_id,
                ]],
            ]))->assertCreated();

        $reporteId = $resp->json('id');
        $ventaId = DB::table('ventas')->where('reporte_id', $reporteId)->value('id');

        // 2) Primera edición: "roba" la venta al segundo vendedor → edicion_critica.
        DB::table('reportes')->where('id', $reporteId)->update(['estado_edicion' => 'APROBADO']);
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/reportes/{$reporteId}/reprocesar", $this->payload($cajero, [
                'agente_id' => $cajero->agente_id, 'efectivo_entregado' => 40,
                'ventas' => [[
                    'venta_id' => $ventaId, 'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'Accesorio manual',
                    'monto_total' => 40, 'efectivo_inicial' => 40, 'vendedor_id' => $otroAgenteId,
                ]],
            ]))->assertOk();

        $primera = DB::table('historial_reportes')->where('reporte_id', $reporteId)->latest('id')->first();
        $this->assertSame('edicion_critica', $primera->accion, 'La primera edición debe ser una alerta crítica.');

        // La segunda edición opera sobre la venta re-creada por el reprocesar anterior.
        $ventaIdActual = DB::table('ventas')->where('reporte_id', $reporteId)->value('id');

        // 3) Segunda edición: restaura la venta al cajero original → edicion_restaurada.
        DB::table('reportes')->where('id', $reporteId)->update(['estado_edicion' => 'APROBADO']);
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/reportes/{$reporteId}/reprocesar", $this->payload($cajero, [
                'agente_id' => $cajero->agente_id, 'efectivo_entregado' => 40,
                'ventas' => [[
                    'venta_id' => $ventaIdActual, 'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'Accesorio manual',
                    'monto_total' => 40, 'efectivo_inicial' => 40, 'vendedor_id' => $cajero->agente_id,
                ]],
            ]))->assertOk();

        $segunda = DB::table('historial_reportes')->where('reporte_id', $reporteId)->latest('id')->first();
        $this->assertSame('edicion_restaurada', $segunda->accion, 'Devolver la venta al vendedor original debe auditarse como restauración.');
        $this->assertStringContainsString('restaurada', strtolower($segunda->detalle));
    }

    public function test_cambio_de_vendedor_sin_alerta_previa_sigue_siendo_edicion_critica(): void
    {
        $cajero = $this->vendedorVinculado('PUNDA50');
        $admin  = Usuario::factory()->admin()->create();
        $otroAgenteId = 2300;

        DB::table('agentes')->insert([
            'id' => $otroAgenteId, 'nombres' => 'Tercer vendedor', 'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50', 'dni' => '00002300',
        ]);

        $resp = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($cajero, [
                'efectivo_entregado' => 40,
                'ventas' => [[
                    'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'Accesorio manual',
                    'monto_total' => 40, 'efectivo_inicial' => 40, 'vendedor_id' => $cajero->agente_id,
                ]],
            ]))->assertCreated();

        $reporteId = $resp->json('id');
        $ventaId = DB::table('ventas')->where('reporte_id', $reporteId)->value('id');

        DB::table('reportes')->where('id', $reporteId)->update(['estado_edicion' => 'APROBADO']);
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/reportes/{$reporteId}/reprocesar", $this->payload($cajero, [
                'agente_id' => $cajero->agente_id, 'efectivo_entregado' => 40,
                'ventas' => [[
                    'venta_id' => $ventaId, 'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'Accesorio manual',
                    'monto_total' => 40, 'efectivo_inicial' => 40, 'vendedor_id' => $otroAgenteId,
                ]],
            ]))->assertOk();

        $historial = DB::table('historial_reportes')->where('reporte_id', $reporteId)->latest('id')->first();
        $this->assertSame('edicion_critica', $historial->accion);
    }
}
