<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 16 — R6: QA integral del modelo de 4 roles.
 *
 * Recorre sistemáticamente endpoints representativos de las 4 secciones de la
 * matriz congelada (negocio, asistencias, config técnica, scoping del agente)
 * instanciando los 4 roles y verificando el estado HTTP esperado por rol.
 *
 * El foco es el GATE de rol (EnsureRole + guards de controlador), no el payload:
 *  - un entero (200)  → el rol accede y la lectura responde OK con BD vacía;
 *  - 'pasa'           → el rol accede al controlador (cualquier estado ≠ 401/403);
 *    se usa en endpoints de MODIFICACIÓN/config donde el 200 exigiría fixtures y
 *    lo que importa es que el gate de rol dejó pasar (típicamente 404/422 luego);
 *  - 403              → el rol queda bloqueado por la matriz.
 *
 * Claves de rol para {@see self::usuarioDeRol()}:
 *  administrador · gerente · jefe_tienda · agente (vinculado) · agente_sin (sin agente_id).
 */
class RolesR6QAIntegralTest extends TestCase
{
    use RefreshDatabase;

    private const PASA = 'pasa';

    protected function setUp(): void
    {
        parent::setUp();
        // La matriz de asistencias resuelve días contra "hoy"; fijamos un día
        // laborable estable para que ?mes=2026-07 responda determinísticamente.
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, 'America/Lima'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuarioDeRol(string $clave): Usuario
    {
        return match ($clave) {
            'administrador' => Usuario::factory()->administrador()->create(),
            'gerente'       => Usuario::factory()->gerente()->create(),
            'jefe_tienda'   => Usuario::factory()->jefeTienda('PUNDA50')->create(),
            // agente_id ficticio: basta para pasar el guard R3 (el scoping filtra a
            // sus filas; con BD vacía la consulta responde 200 igualmente).
            'agente'        => Usuario::factory()->agenteVentas(1)->create(),
            'agente_sin'    => Usuario::factory()->agenteVentas(null)->create(),
        };
    }

    /**
     * @dataProvider matrizEndpoints
     *
     * @param  array<string,int|string>  $esperadoPorRol
     */
    public function test_matriz_de_roles(string $method, string $url, array $payload, array $esperadoPorRol): void
    {
        foreach ($esperadoPorRol as $rol => $esperado) {
            $usuario = $this->usuarioDeRol($rol);

            $response = $this->actingAs($usuario, 'sanctum')
                ->json($method, $url, $payload);

            $mensaje = sprintf('[%s %s] rol "%s" devolvió %d', $method, $url, $rol, $response->status());

            if ($esperado === 403) {
                $this->assertSame(403, $response->status(), $mensaje);
            } elseif ($esperado === self::PASA) {
                // El gate de rol dejó pasar: cualquier cosa menos 401/403.
                $this->assertNotContains($response->status(), [401, 403], $mensaje);
            } else {
                $this->assertSame($esperado, $response->status(), $mensaje);
            }
        }
    }

    /**
     * @return array<string,array{0:string,1:string,2:array<string,mixed>,3:array<string,int|string>}>
     */
    public static function matrizEndpoints(): array
    {
        $A = 'administrador';
        $G = 'gerente';
        $J = 'jefe_tienda';
        $V = 'agente';

        return [
            // ── Sección 1 · Negocio ─────────────────────────────────────────
            'negocio: inventario (VER)' => [
                'GET', '/api/v1/inventario', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'negocio: inventario/matriz' => [
                'GET', '/api/v1/inventario/matriz', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'negocio: planilla (JT ❌)' => [
                'GET', '/api/v1/planilla/2026-06', [],
                [$A => 200, $G => 200, $J => 403, $V => 403],
            ],
            'negocio: usuarios (JT ❌)' => [
                'GET', '/api/v1/usuarios', [],
                [$A => 200, $G => 200, $J => 403, $V => 403],
            ],
            'negocio: tiendas (JT ❌)' => [
                'GET', '/api/v1/tiendas', [],
                [$A => 200, $G => 200, $J => 403, $V => 403],
            ],

            // ── Sección 2 · Asistencias (R4) ────────────────────────────────
            // Lectura: admin/gerente/jefe_tienda VEN; agente no.
            'asistencias: listado (VER)' => [
                'GET', '/api/v1/asistencias', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'asistencias: presencia (VER)' => [
                'GET', '/api/v1/asistencias-admin/presencia', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'asistencias: matriz (VER)' => [
                'GET', '/api/v1/asistencias/matriz?mes=2026-07', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'asistencias: fraude-dispositivos (VER)' => [
                'GET', '/api/v1/asistencias/fraude-dispositivos', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            'asistencias: fotos-pendientes (VER)' => [
                'GET', '/api/v1/asistencias/fotos-pendientes', [],
                [$A => 200, $G => 200, $J => 200, $V => 403],
            ],
            // Modificación: SOLO admin/gerente (anticorrupción). El jefe_tienda NO.
            'asistencias: excepción/falta (MODIFICAR)' => [
                'POST', '/api/v1/asistencias/excepcion', [],
                [$A => self::PASA, $G => self::PASA, $J => 403, $V => 403],
            ],
            'asistencias: photo-action (MODIFICAR)' => [
                'POST', '/api/v1/asistencias/1/photo-action', ['action' => 'aprobar'],
                [$A => self::PASA, $G => self::PASA, $J => 403, $V => 403],
            ],

            // ── Sección 3 · Configuración técnica ───────────────────────────
            'config: diagnóstico (SOLO admin)' => [
                'GET', '/api/v1/diagnostico', [],
                [$A => self::PASA, $G => 403, $J => 403, $V => 403],
            ],
            'config: configure-sunat (SOLO admin)' => [
                'POST', '/api/v1/facturacion-config/configure-sunat', [],
                [$A => self::PASA, $G => 403, $J => 403, $V => 403],
            ],

            // ── Sección 4 · Scoping del agente de ventas ────────────────────
            'scoping: mis-reportes (agente vinculado ve lo suyo)' => [
                'GET', '/api/v1/reportes/mis-reportes', [],
                [$A => 200, $G => 200, $J => 200, $V => 200],
            ],
            'scoping: mis-reportes sin vínculo → 403' => [
                'GET', '/api/v1/reportes/mis-reportes', [],
                ['agente_sin' => 403],
            ],
            'scoping: listado global de reportes vetado al agente' => [
                'GET', '/api/v1/reportes', [],
                [$V => 403],
            ],
        ];
    }
}
