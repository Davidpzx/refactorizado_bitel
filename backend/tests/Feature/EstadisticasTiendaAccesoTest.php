<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstadisticasTiendaAccesoTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporteConVenta(string $tiendaId, string $tipoVenta = 'EQUIPO'): void
    {
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => $tiendaId,
            'fecha' => now()->toDateString(),
            'estado' => 'enviado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ventas')->insert([
            'reporte_id' => $reporteId,
            'vendedor_id' => 1,
            'tipo_venta' => $tipoVenta,
            'monto_total' => 100,
            'comision_generada' => 10,
            'creado_en' => now(),
        ]);
    }

    public function test_tienda_puede_ver_estadisticas_de_su_propia_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporteConVenta('T01');
        $this->crearReporteConVenta('T02');

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/estadisticas/ventas')
            ->assertOk()
            ->assertJsonPath('totales.total_ventas', 1);
    }

    public function test_tienda_no_ve_ventas_de_otra_tienda_aunque_lo_pida_por_parametro(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporteConVenta('T01');
        $this->crearReporteConVenta('T02');

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/estadisticas/ventas?tienda=T02')
            ->assertOk()
            ->assertJsonPath('totales.total_ventas', 1);
    }

    public function test_productividad_scoped_a_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '11111111', 'nombres' => 'Agente T01', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
        ]);
        DB::table('agentes')->insert([
            'id' => 2, 'dni' => '22222222', 'nombres' => 'Agente T02', 'estado' => 'ACTIVO', 'tienda_base' => 'T02',
        ]);

        $reporteT01 = DB::table('reportes')->insertGetId([
            'agente_id' => 1, 'tienda_id' => 'T01', 'fecha' => now()->toDateString(), 'estado' => 'enviado',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reporteT02 = DB::table('reportes')->insertGetId([
            'agente_id' => 2, 'tienda_id' => 'T02', 'fecha' => now()->toDateString(), 'estado' => 'enviado',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ventas')->insert([
            'reporte_id' => $reporteT01, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO', 'monto_total' => 100, 'creado_en' => now(),
        ]);
        DB::table('ventas')->insert([
            'reporte_id' => $reporteT02, 'vendedor_id' => 2, 'tipo_venta' => 'EQUIPO', 'monto_total' => 100, 'creado_en' => now(),
        ]);

        $response = $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/estadisticas/productividad')
            ->assertOk();

        $vendedores = collect($response->json('ranking'))->pluck('vendedor_id');
        $this->assertSame([1], $vendedores->values()->all());
    }

    public function test_estadisticas_exportar_accesible_a_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporteConVenta('T01');

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/estadisticas/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_ve_estadisticas_de_todas_las_tiendas(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearReporteConVenta('T01');
        $this->crearReporteConVenta('T02');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/ventas')
            ->assertOk()
            ->assertJsonPath('totales.total_ventas', 2);
    }
}
