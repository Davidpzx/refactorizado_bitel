<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de ConstanciaController::reporte(): antes cualquier
 * usuario autenticado podia descargar el PDF de cualquier reporte (montos,
 * comisiones, DNIs) sin validar rol/tienda.
 */
class ConstanciaReporteAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tienda = 'PUNDA50'): int
    {
        $cajero = $this->vendedorVinculado($tienda);

        $response = $this->actingAs($cajero, 'sanctum')->postJson('/api/v1/reportes', [
            'agente_id'           => $cajero->agente_id,
            'tienda_id'           => $tienda,
            'fecha'               => '2026-07-03',
            'caja_inicial'        => 50,
            'efectivo_entregado'  => 50,
        ]);

        $response->assertCreated();

        return $response->json('id');
    }

    private function asegurarConfiguraciones(): void
    {
        DB::table('configuracion_empresa')->insert([
            'razon_social' => 'BITEL TELECOM S.A.C.',
            'ruc'          => '20512345678',
        ]);
    }

    public function test_admin_puede_ver_el_pdf_de_cualquier_reporte(): void
    {
        $this->asegurarConfiguraciones();
        $reporteId = $this->crearReporte('PUNDA50');

        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_usuario_puede_ver_el_pdf_de_un_reporte_de_su_propia_tienda(): void
    {
        $this->asegurarConfiguraciones();
        $reporteId = $this->crearReporte('PUNDA50');

        $otroDeLaMismaTienda = $this->vendedorVinculado('PUNDA50');

        $response = $this->actingAs($otroDeLaMismaTienda, 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_usuario_de_otra_tienda_recibe_403(): void
    {
        $reporteId = $this->crearReporte('PUNDA50');

        $usuarioDeOtraTienda = $this->vendedorVinculado('PUNDA11');

        $response = $this->actingAs($usuarioDeOtraTienda, 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");

        $response->assertForbidden();
    }

    public function test_usuario_sin_tienda_id_falla_cerrado_con_403(): void
    {
        $reporteId = $this->crearReporte('PUNDA50');

        $usuarioSinTienda = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");

        $response->assertForbidden();
    }
}
