<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventarioRestaurarTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(string $tienda = 'PUNDA50'): int
    {
        $agenteId = 5000 + DB::table('agentes')->count() + 1;
        DB::table('agentes')->insert([
            'id'          => $agenteId,
            'nombres'     => "Agente {$agenteId}",
            'estado'      => 'ACTIVO',
            'tienda_base' => $tienda,
            'dni'         => str_pad((string) $agenteId, 8, '0', STR_PAD_LEFT),
        ]);

        return $agenteId;
    }

    private function crearEquipoVendido(int $vendedorId, string $tienda, ?int $reporteVentaId = null): int
    {
        return DB::table('inventario_tiendas')->insertGetId([
            'tienda_id'        => $tienda,
            'producto_nombre'  => 'iPhone 15',
            'tipo'             => 'EQUIPO',
            'cantidad'         => 0,
            'estado'           => 'VENDIDO',
            'precio_costo'     => 1000,
            'precio_minimo'    => 1200,
            'precio_normal'    => 1500,
            'fecha_registro'   => now(),
            'fecha_venta'      => now(),
            'vendido_por_id'   => $vendedorId,
            'reporte_venta_id' => $reporteVentaId,
        ]);
    }

    private function crearReporte(string $tienda, int $agenteId, string $fecha): int
    {
        return DB::table('reportes')->insertGetId([
            'agente_id' => $agenteId,
            'tienda_id' => $tienda,
            'fecha'     => $fecha,
            'estado'    => 'enviado',
        ]);
    }

    private function crearVentaEquipo(int $reporteId, int $vendedorId, int $inventarioTiendaId): int
    {
        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id'        => $reporteId,
            'vendedor_id'       => $vendedorId,
            'tipo_venta'        => 'EQUIPO',
            'monto_total'       => 1500,
            'efectivo_inicial'  => 1500,
            'comision_generada' => 5,
            'comision_estado'   => 'ACTIVA',
            'creado_en'         => now(),
        ]);

        DB::table('venta_equipos')->insert([
            'venta_id'             => $ventaId,
            'inventario_tienda_id' => $inventarioTiendaId,
            'producto_nombre_snap' => 'iPhone 15',
            'imei_serial_snap'     => '123456789012345',
            'tipo_item'            => 'EQUIPO',
            'tipo_pago'            => 'CONTADO',
            'precio_venta'         => 1500,
            'costo_snap'           => 1000,
            'ganancia_snap'        => 500,
        ]);

        return $ventaId;
    }

    public function test_restaurar_equipo_vendido_anula_la_venta_y_devuelve_stock(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $vendedorId = $this->crearAgente();
        $reporteId = $this->crearReporte('PUNDA50', $vendedorId, '2026-06-10');
        $equipoId = $this->crearEquipoVendido($vendedorId, 'PUNDA50', $reporteId);
        $ventaId = $this->crearVentaEquipo($reporteId, $vendedorId, $equipoId);

        DB::table('ventas')->where('id', $ventaId)->update([
            'desembolso_confirmado_por' => $admin->id,
            'desembolso_confirmado_en'  => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$equipoId}/restaurar")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $equipo = DB::table('inventario_tiendas')->where('id', $equipoId)->first();
        $this->assertSame('DISPONIBLE', $equipo->estado);
        $this->assertNull($equipo->fecha_venta);
        $this->assertNull($equipo->vendido_por_id);

        $venta = DB::table('ventas')->where('id', $ventaId)->first();
        $this->assertSame('ANULADA', $venta->comision_estado);
        $this->assertSame(0.0, (float) $venta->comision_generada);
        $this->assertNull($venta->desembolso_confirmado_por);
        $this->assertNull($venta->desembolso_confirmado_en);

        $historial = DB::table('historial_inventario')
            ->where('producto_id', $equipoId)
            ->where('accion', 'SUMA')
            ->first();
        $this->assertNotNull($historial);
        $this->assertStringContainsString((string) $ventaId, $historial->observacion);
    }

    public function test_restaurar_bloqueado_si_venta_ya_esta_en_planilla_pagada(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $vendedorId = $this->crearAgente();
        $reporteId = $this->crearReporte('PUNDA50', $vendedorId, '2026-06-10');
        $equipoId = $this->crearEquipoVendido($vendedorId, 'PUNDA50', $reporteId);
        $ventaId = $this->crearVentaEquipo($reporteId, $vendedorId, $equipoId);

        DB::table('pagos_planilla')->insert([
            'agente_id'           => $vendedorId,
            'fecha_inicio'        => '2026-06-01',
            'fecha_fin'           => '2026-06-30',
            'sueldo_base'         => 1000,
            'bonos_comisiones'    => 0,
            'descuento_tardanza'  => 0,
            'descuento_adelantos' => 0,
            'total_pagado'        => 1000,
            'estado'              => 'PAGADO',
            'fecha_pago'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$equipoId}/restaurar")
            ->assertStatus(422);

        // Nada debe cambiar: ni el equipo, ni la venta, ni el historial.
        $equipo = DB::table('inventario_tiendas')->where('id', $equipoId)->first();
        $this->assertSame('VENDIDO', $equipo->estado);
        $this->assertNotNull($equipo->fecha_venta);

        $venta = DB::table('ventas')->where('id', $ventaId)->first();
        $this->assertSame('ACTIVA', $venta->comision_estado);
        $this->assertSame(5.0, (float) $venta->comision_generada);

        $this->assertSame(0, DB::table('historial_inventario')
            ->where('producto_id', $equipoId)
            ->count());
    }

    public function test_restaurar_equipo_sin_venta_asociada_sigue_funcionando(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $vendedorId = $this->crearAgente();
        $equipoId = $this->crearEquipoVendido($vendedorId, 'PUNDA50');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$equipoId}/restaurar")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $equipo = DB::table('inventario_tiendas')->where('id', $equipoId)->first();
        $this->assertSame('DISPONIBLE', $equipo->estado);

        $this->assertSame(1, DB::table('historial_inventario')
            ->where('producto_id', $equipoId)
            ->where('accion', 'SUMA')
            ->count());
    }

    public function test_dashboard_kpis_excluye_ganancia_de_venta_anulada(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $vendedorId = $this->crearAgente();
        $reporteId = $this->crearReporte('PUNDA50', $vendedorId, now()->toDateString());
        DB::table('reportes')->where('id', $reporteId)->update(['estado' => 'enviado']);
        $equipoId = $this->crearEquipoVendido($vendedorId, 'PUNDA50', $reporteId);
        $this->crearVentaEquipo($reporteId, $vendedorId, $equipoId);

        // Restaurar anula la venta → su ganancia_snap (500) no debe contarse.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$equipoId}/restaurar")
            ->assertOk();

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/kpis');
        $resp->assertOk();
        $this->assertSame(0.0, (float) $resp->json('ganancia_total'));
    }

    public function test_historial_kpis_excluye_ganancia_de_venta_anulada(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $vendedorId = $this->crearAgente();
        $reporteId = $this->crearReporte('PUNDA50', $vendedorId, now()->toDateString());
        DB::table('reportes')->where('id', $reporteId)->update(['estado' => 'enviado']);
        $equipoId = $this->crearEquipoVendido($vendedorId, 'PUNDA50', $reporteId);
        $this->crearVentaEquipo($reporteId, $vendedorId, $equipoId);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/inventario/{$equipoId}/restaurar")
            ->assertOk();

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/historial/kpis');
        $resp->assertOk();
        $this->assertSame(0.0, (float) $resp->json('ganancia_total'));
    }
}
