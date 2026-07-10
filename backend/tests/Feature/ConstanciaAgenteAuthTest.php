<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de ConstanciaController::agente() y ::boleta():
 * mismo patron copiado de AgenteController::show() con agente->tienda_base.
 */
class ConstanciaAgenteAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(?string $tienda = 'PUNDA50'): Agente
    {
        return Agente::create([
            'dni' => '87654321', 'nombres' => 'Agente Constancia', 'estado' => 'ACTIVO',
            'tienda_base' => $tienda, 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
    }

    private function asegurarConfiguraciones(): void
    {
        DB::table('configuracion_empresa')->insert([
            'razon_social' => 'BITEL TELECOM S.A.C.',
            'ruc'          => '20512345678',
        ]);
    }

    // ── /constancias/agente/{id} ──────────────────────────────────────────

    public function test_usuario_de_otra_tienda_no_ve_certificado_de_agente(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/agente/{$agente->id}")
            ->assertForbidden();
    }

    public function test_usuario_sin_tienda_id_y_agente_sin_tienda_base_no_ve_certificado(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente(null);
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/agente/{$agente->id}")
            ->assertForbidden();
    }

    public function test_usuario_de_la_misma_tienda_ve_certificado(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/agente/{$agente->id}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ── /constancias/boleta/{id} ──────────────────────────────────────────

    private function crearBoletaPara(Agente $agente): int
    {
        return DB::table('pagos_planilla')->insertGetId([
            'agente_id'           => $agente->id,
            'fecha_inicio'        => '2026-06-01',
            'fecha_fin'           => '2026-06-15',
            'sueldo_base'         => 1200,
            'bonos_comisiones'    => 0,
            'descuento_tardanza'  => 0,
            'descuento_adelantos' => 0,
            'total_pagado'        => 1200,
            'estado'              => 'PENDIENTE',
            'fecha_pago'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function test_usuario_de_otra_tienda_no_ve_boleta(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente('PUNDA50');
        $boletaId = $this->crearBoletaPara($agente);
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/boleta/{$boletaId}")
            ->assertForbidden();
    }

    public function test_usuario_sin_tienda_id_y_agente_sin_tienda_base_no_ve_boleta(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente(null);
        $boletaId = $this->crearBoletaPara($agente);
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/boleta/{$boletaId}")
            ->assertForbidden();
    }

    public function test_usuario_de_la_misma_tienda_ve_boleta(): void
    {
        $this->asegurarConfiguraciones();
        $agente = $this->crearAgente('PUNDA50');
        $boletaId = $this->crearBoletaPara($agente);
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->get("/api/v1/constancias/boleta/{$boletaId}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
