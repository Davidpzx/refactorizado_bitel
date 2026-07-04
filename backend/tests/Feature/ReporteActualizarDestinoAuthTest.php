<?php

namespace Tests\Feature;

use App\Models\Reporte;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de ReporteController::actualizarDestino(): esta es una
 * ruta de ESCRITURA que mueve el destino del efectivo del dia. Antes del fix comparaba
 * `$reporte->tienda_id === $user->tienda_id` (via abort_unless inverso); si ambos eran
 * el mismo valor "vacio" el guard se colaba y un no-admin sin tienda podia modificar el
 * destino de efectivo de un reporte ajeno.
 */
class ReporteActualizarDestinoAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tienda): Reporte
    {
        return Reporte::create([
            'agente_id'         => 1,
            'tienda_id'         => $tienda,
            'fecha'             => '2026-07-03',
            'destino_efectivo'  => 'TIENDA',
        ]);
    }

    public function test_admin_puede_actualizar_destino_de_cualquier_reporte(): void
    {
        $reporte = $this->crearReporte('PUNDA50');
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporte->id}/destino-efectivo", [
                'destino_efectivo' => 'BANCO',
            ]);

        $response->assertOk();
        $this->assertSame('BANCO', $reporte->fresh()->destino_efectivo);
    }

    public function test_usuario_puede_actualizar_destino_de_reporte_de_su_propia_tienda(): void
    {
        $reporte = $this->crearReporte('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporte->id}/destino-efectivo", [
                'destino_efectivo' => 'BANCO',
            ]);

        $response->assertOk();
        $this->assertSame('BANCO', $reporte->fresh()->destino_efectivo);
    }

    public function test_usuario_de_otra_tienda_recibe_403_y_no_modifica_destino(): void
    {
        $reporte = $this->crearReporte('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporte->id}/destino-efectivo", [
                'destino_efectivo' => 'BANCO',
            ]);

        $response->assertForbidden();
        $this->assertSame('TIENDA', $reporte->fresh()->destino_efectivo);
    }

    /**
     * Hueco null===null: la columna reportes.tienda_id es NOT NULL, asi que el
     * equivalente de "sin tienda" en este esquema es cadena vacia. Antes del fix,
     * un reporte con tienda_id='' y un usuario no-admin con tienda_id='' hacian
     * match (`'' === ''`) y el guard invertido `!== ` no bloqueaba: el efectivo
     * quedaba expuesto a modificacion por cualquier no-admin sin tienda asignada.
     */
    public function test_usuario_sin_tienda_y_reporte_sin_tienda_no_modifica_destino(): void
    {
        $reporte = $this->crearReporte('');
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporte->id}/destino-efectivo", [
                'destino_efectivo' => 'BANCO',
            ]);

        $response->assertForbidden();
        $this->assertSame('TIENDA', $reporte->fresh()->destino_efectivo);
    }
}
