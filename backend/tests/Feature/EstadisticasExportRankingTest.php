<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EstadisticasExportRankingTest extends TestCase
{
    use RefreshDatabase;

    private function agente(int $id, string $tienda): void
    {
        DB::table('agentes')->insert([
            'id' => $id,
            'dni' => str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            'nombres' => "Agente $id",
            'estado' => 'ACTIVO',
            'tienda_base' => $tienda,
        ]);
    }

    private function reporte(string $tienda, int $agenteId = 1): int
    {
        return DB::table('reportes')->insertGetId([
            'agente_id' => $agenteId,
            'tienda_id' => $tienda,
            'fecha' => now()->toDateString(),
            'estado' => 'enviado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Devuelve el id de la venta creada. */
    private function venta(int $reporteId, array $attrs = []): int
    {
        return DB::table('ventas')->insertGetId(array_merge([
            'reporte_id' => $reporteId,
            'vendedor_id' => 1,
            'tipo_venta' => 'POSTPAGO',
            'monto_total' => 100,
            'comision_generada' => 10,
            'es_remate' => false,
            'cross_selling' => false,
            'creado_en' => now(),
        ], $attrs));
    }

    private function linea(int $ventaId, string $plan, string $tipoAlta = 'ALTA_NUEVA', int $cantidad = 1): void
    {
        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaId,
            'plan_nombre_snap' => $plan,
            'tipo_alta' => $tipoAlta,
            'cantidad' => $cantidad,
        ]);
    }

    // ── Gap 2: exclusión de ranking ────────────────────────────────────────────

    public function test_ranking_excluye_remate_upgrade_y_paquete(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');

        // Sí cuenta.
        $ok = $this->venta($reporte);
        $this->linea($ok, 'Plan Max', 'ALTA_NUEVA');

        // No cuentan.
        $this->venta($reporte, ['es_remate' => true]);
        $this->venta($reporte, ['subtipo' => 'PAQUETE']);
        $upg = $this->venta($reporte);
        $this->linea($upg, 'Plan Max', 'UPGRADE');

        $ranking = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/productividad')
            ->assertOk()
            ->json('ranking');

        $this->assertCount(1, $ranking);
        $this->assertSame(1, (int) $ranking[0]['total']);
    }

    public function test_ranking_categorizado_tambien_excluye(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');

        $ok = $this->venta($reporte);
        $this->linea($ok, 'Plan Max', 'ALTA_NUEVA');
        $this->venta($reporte, ['es_remate' => true]);

        $ranking = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/ranking?categoria=postpago')
            ->assertOk()
            ->json('ranking');

        $this->assertCount(1, $ranking);
        $this->assertSame(1, (int) $ranking[0]['total']);
    }

    // Paridad con el legacy (obtener_ranking_agentes.php:70-131): las exclusiones de
    // remate/paquete/UPGRADE solo cortan la rama postpago/plan_online. La rama de
    // equipos_accesorios cuenta todo sin exclusiones y chip_prepago está explícitamente
    // exento — RankingVentaScope::aplicar() a secas sobre-excluía estas categorías.
    public function test_ranking_general_no_sobre_excluye_equipo_ni_prepago_con_remate_o_upgrade(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');

        // EQUIPO con es_remate=true: debe contar (rama equipos_accesorios sin exclusiones).
        $this->venta($reporte, ['tipo_venta' => 'EQUIPO', 'es_remate' => true]);

        // PREPAGO (chip) con línea UPGRADE: chip_prepago está exento en el legacy.
        $chip = $this->venta($reporte, ['tipo_venta' => 'PREPAGO']);
        $this->linea($chip, 'Chip Prepago', 'UPGRADE');

        // POSTPAGO con es_remate=true: sigue excluido (paridad ya verificada).
        $this->venta($reporte, ['tipo_venta' => 'POSTPAGO', 'es_remate' => true]);

        $ranking = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/productividad')
            ->assertOk()
            ->json('ranking');

        $this->assertCount(1, $ranking);
        $this->assertSame(2, (int) $ranking[0]['total']);
        $this->assertSame(1, (int) $ranking[0]['equipos']);
        $this->assertSame(1, (int) $ranking[0]['prepago']);
        $this->assertSame(0, (int) $ranking[0]['postpago']);
    }

    public function test_ranking_categorizado_equipos_no_excluye_remate_ni_upgrade(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');
        $this->venta($reporte, ['tipo_venta' => 'EQUIPO', 'es_remate' => true]);

        $ranking = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/ranking?categoria=equipos')
            ->assertOk()
            ->json('ranking');

        $this->assertCount(1, $ranking);
        $this->assertSame(1, (int) $ranking[0]['total']);
    }

    public function test_ranking_categorizado_chips_no_excluye_upgrade(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');
        $chip = $this->venta($reporte, ['tipo_venta' => 'PREPAGO']);
        $this->linea($chip, 'Chip Prepago', 'UPGRADE');

        $ranking = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/ranking?categoria=chips')
            ->assertOk()
            ->json('ranking');

        $this->assertCount(1, $ranking);
        $this->assertSame(1, (int) $ranking[0]['total']);
    }

    // ── Gap 1: reasignación cross_selling → tienda_destino ──────────────────────

    public function test_por_tienda_reasigna_cross_selling_a_tienda_destino(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');

        // Venta de apoyo: reporte en T01 pero cuenta para T02.
        $this->venta($reporte, [
            'tipo_venta' => 'PREPAGO',
            'cross_selling' => true,
            'tienda_destino' => 'T02',
        ]);

        $porTienda = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/estadisticas/ventas')
            ->assertOk()
            ->json('por_tienda'));

        $t02 = $porTienda->firstWhere('tienda_id', 'T02');
        $this->assertNotNull($t02, 'La venta cross_selling debe atribuirse a T02');
        $this->assertSame(1, (int) $t02['total']);
        $this->assertNull($porTienda->firstWhere('tienda_id', 'T01'));
    }

    // ── Gap 1: export completo ──────────────────────────────────────────────────

    public function test_export_devuelve_xlsx_con_todas_las_hojas(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');
        $venta = $this->venta($reporte, ['tipo_venta' => 'PREPAGO']);
        $this->linea($venta, 'Prepago Papa');
        $eqVenta = $this->venta($reporte, ['tipo_venta' => 'EQUIPO']);
        DB::table('venta_equipos')->insert([
            'venta_id' => $eqVenta,
            'producto_nombre_snap' => 'Samsung A15',
            'tipo_item' => 'EQUIPO',
            'tipo_pago' => 'CUOTAS',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/estadisticas/exportar');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $names = IOFactory::load($tmp)->getSheetNames();
        @unlink($tmp);

        foreach (['Resumen', 'Tiendas', 'Agentes', 'Top Equipos', 'Top Accesorios', 'Top Planes'] as $hoja) {
            $this->assertContains($hoja, $names, "Falta la hoja '$hoja' en el export");
        }
    }

    public function test_export_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/estadisticas/exportar')->assertUnauthorized();
    }

    public function test_top_planes_excluye_por_subtipo_no_por_texto_del_plan(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->agente(1, 'T01');
        $reporte = $this->reporte('T01');

        // subtipo=PAQUETE debe excluirse aunque el nombre del plan no diga "paquete".
        $paquete = $this->venta($reporte, ['tipo_venta' => 'POSTPAGO', 'subtipo' => 'PAQUETE']);
        $this->linea($paquete, 'Plan Max');

        // Un plan cuyo nombre contiene "Paquete" pero NO es subtipo=PAQUETE sí debe contar.
        $normal = $this->venta($reporte, ['tipo_venta' => 'POSTPAGO']);
        $this->linea($normal, 'Plan Paquete Familiar');

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/estadisticas/exportar');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheetByName('Top Planes');
        @unlink($tmp);

        $planes = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $plan = $sheet->getCell("B{$row}")->getValue();
            if ($plan !== null) {
                $planes[] = $plan;
            }
        }

        $this->assertContains('Plan Paquete Familiar', $planes);
        $this->assertNotContains('Plan Max', $planes);
    }
}
