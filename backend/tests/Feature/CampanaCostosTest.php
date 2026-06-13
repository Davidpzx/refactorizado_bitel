<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampanaCostosTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lista_ventas_normalizadas_sin_costo(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaEquipoId = $this->crearVentaEquipoSinCosto();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/campana-costos')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.rc_id', $ventaEquipoId)
            ->assertJsonPath('items.0.producto', 'Equipo prueba')
            ->assertJsonPath('items.0.tienda', 'PUNDA50')
            ->assertJsonPath('data.0.rc_id', $ventaEquipoId);
    }

    public function test_admin_fija_costo_y_la_venta_sale_de_la_campana(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ventaEquipoId = $this->crearVentaEquipoSinCosto();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/reporte-categorias/{$ventaEquipoId}/fijar-costo", [
                'precio_costo' => 700,
                'precio_venta' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ganancia', 300)
            ->assertJsonPath('costo', 700);

        $this->assertDatabaseHas('venta_equipos', [
            'id' => $ventaEquipoId,
            'precio_venta' => 1000,
            'costo_snap' => 700,
            'ganancia_snap' => 300,
        ]);

        $this->getJson('/api/v1/inventario/campana-costos')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_vendedor_no_puede_fijar_costo(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $ventaEquipoId = $this->crearVentaEquipoSinCosto();

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/reporte-categorias/{$ventaEquipoId}/fijar-costo", [
                'precio_costo' => 700,
            ])
            ->assertForbidden();

        $this->assertSame(
            0.0,
            (float) DB::table('venta_equipos')->where('id', $ventaEquipoId)->value('costo_snap')
        );
    }

    private function crearVentaEquipoSinCosto(): int
    {
        $agente = Usuario::factory()->vendedor('PUNDA50')->create();

        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => $agente->id,
            'tienda_id' => 'PUNDA50',
            'usuario_id' => $agente->id,
            'fecha' => '2026-06-13',
            'estado' => 'borrador',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId,
            'vendedor_id' => $agente->id,
            'tipo_venta' => 'EQUIPO',
            'monto_total' => 1000,
            'efectivo_inicial' => 1000,
            'comision_generada' => 0,
            'comision_estado' => 'ACTIVA',
            'es_remate' => false,
            'es_extranjero' => false,
            'creado_en' => now(),
        ]);

        return DB::table('venta_equipos')->insertGetId([
            'venta_id' => $ventaId,
            'inventario_tienda_id' => 0,
            'producto_nombre_snap' => 'Equipo prueba',
            'imei_serial_snap' => 'IMEI-PRUEBA',
            'tipo_item' => 'EQUIPO',
            'tipo_pago' => 'CONTADO',
            'precio_venta' => 1000,
            'costo_snap' => 0,
            'ganancia_snap' => null,
            'por_cobrar_financiera' => 0,
        ]);
    }
}
