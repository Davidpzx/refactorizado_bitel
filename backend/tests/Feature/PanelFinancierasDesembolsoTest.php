<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PanelFinancierasDesembolsoTest extends TestCase
{
    use RefreshDatabase;

    private function crearVentaConEquipo(array $ventaOverrides = [], array $equipoOverrides = []): int
    {
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-06-10',
            'estado' => 'enviado',
            'destino_efectivo' => 'TIENDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ventaId = DB::table('ventas')->insertGetId(array_merge([
            'reporte_id' => $reporteId,
            'vendedor_id' => 1,
            'tipo_venta' => 'EQUIPO',
            'comision_generada' => 0,
            'comision_estado' => 'PENDIENTE',
            'creado_en' => now(),
        ], $ventaOverrides));

        DB::table('venta_equipos')->insert(array_merge([
            'venta_id' => $ventaId,
            'producto_nombre_snap' => 'Equipo X',
            'tipo_item' => 'EQUIPO',
            'tipo_pago' => 'CUOTAS',
            'financiera' => 'Compartamos',
            'precio_venta' => 1000,
            'costo_snap' => 0,
            'ganancia_snap' => null,
            'por_cobrar_financiera' => 1000,
        ], $equipoOverrides));

        return $ventaId;
    }

    public function test_confirmar_con_costo_fijado_recalcula_ganancia_y_audita(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaId = $this->crearVentaConEquipo([], ['costo_snap' => 700, 'ganancia_snap' => null]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ventas', [
            'id' => $ventaId,
            'comision_estado' => 'APROBADA',
            'desembolso_confirmado_por' => $admin->id,
        ]);

        $venta = DB::table('ventas')->where('id', $ventaId)->first();
        $this->assertNotNull($venta->desembolso_confirmado_en);

        $equipo = DB::table('venta_equipos')->where('venta_id', $ventaId)->first();
        $this->assertEquals(300.0, (float) $equipo->ganancia_snap);
    }

    public function test_confirmar_sin_costo_conserva_ganancia_previa(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaId = $this->crearVentaConEquipo([], ['costo_snap' => 0, 'ganancia_snap' => 150]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertOk();

        $this->assertDatabaseHas('ventas', [
            'id' => $ventaId,
            'comision_estado' => 'APROBADA',
        ]);

        $equipo = DB::table('venta_equipos')->where('venta_id', $ventaId)->first();
        $this->assertEquals(150.0, (float) $equipo->ganancia_snap);
    }

    public function test_doble_confirmacion_rechaza_con_422_sin_cambios_adicionales(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaId = $this->crearVentaConEquipo([], ['costo_snap' => 700]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertOk();

        $ventaTrasPrimera = DB::table('ventas')->where('id', $ventaId)->first();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertStatus(422);

        $ventaTrasSegunda = DB::table('ventas')->where('id', $ventaId)->first();
        $this->assertEquals($ventaTrasPrimera->comision_generada, $ventaTrasSegunda->comision_generada);
        $this->assertEquals($ventaTrasPrimera->desembolso_confirmado_en, $ventaTrasSegunda->desembolso_confirmado_en);
        $this->assertEquals($ventaTrasPrimera->desembolso_confirmado_por, $ventaTrasSegunda->desembolso_confirmado_por);
    }

    public function test_revertir_limpia_auditoria_y_no_toca_ganancia(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaId = $this->crearVentaConEquipo([], ['costo_snap' => 700]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/revertir-desembolso")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ventas', [
            'id' => $ventaId,
            'comision_estado' => 'PENDIENTE',
            'comision_generada' => 0,
            'desembolso_confirmado_por' => null,
            'desembolso_confirmado_en' => null,
        ]);

        $equipo = DB::table('venta_equipos')->where('venta_id', $ventaId)->first();
        $this->assertEquals(300.0, (float) $equipo->ganancia_snap);
    }

    public function test_solo_admin_puede_confirmar_desembolso(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $ventaId = $this->crearVentaConEquipo([], ['costo_snap' => 700]);

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertStatus(403);
    }
}
