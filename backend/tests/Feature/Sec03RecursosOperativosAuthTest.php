<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-03: los recursos operativos (inventario con costos, chips, traslados,
 * tickets, constancias, bipay cajero, CRM/leads, clientes, ventas) estaban bajo
 * `auth:sanctum` pero SIN capa `role:`. Cualquier usuario autenticado —incluido un
 * usuario con `rol = 'agente'`— podía listar inventario global, mover chips, ver
 * tickets/traslados de todas las tiendas y leer/escribir el CRM completo.
 *
 * Esta suite fija la matriz mínima admin/tienda: un usuario `agente` recibe 403 en
 * cada grupo, y un usuario `tienda` (alias vendedor en EnsureRole) NO es rechazado
 * por la capa de rol. El scoping fino por tienda dentro de cada controller es otra
 * tarea (ver hallazgos en plan/.worker-titan-SEC-03.log).
 */
class Sec03RecursosOperativosAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un GET representativo por cada grupo protegido en SEC-03. Se usan lecturas
     * (index/show) para no depender de `open.shift` ni de payloads de escritura;
     * EnsureRole aborta antes de llegar al controller, así que no se toca la BD.
     *
     * @return array<string, array{0: string}>
     */
    public static function rutasProtegidas(): array
    {
        return [
            'clientes'            => ['/api/v1/clientes'],
            'ventas'              => ['/api/v1/ventas'],
            'inventario'          => ['/api/v1/inventario'],
            'inventario kardex'   => ['/api/v1/inventario/kardex'],
            'inventario matriz'   => ['/api/v1/inventario/matriz'],
            'bipay saldo'         => ['/api/v1/bipay/saldo'],
            'bipay transacciones' => ['/api/v1/bipay/transacciones'],
            'bipay cajero estado' => ['/api/v1/bipay/cajero/estado'],
            'tickets'             => ['/api/v1/tickets'],
            'chips'               => ['/api/v1/chips'],
            'traslados'           => ['/api/v1/traslados'],
            'traslados-chips'     => ['/api/v1/traslados-chips'],
            'inventario-chips'    => ['/api/v1/inventario-chips'],
            'constancias'         => ['/api/v1/constancias/traslado'],
            'crm dashboard'       => ['/api/v1/crm/dashboard'],
            'crm pipeline'        => ['/api/v1/crm/pipeline'],
            'crm temperatura'     => ['/api/v1/crm/temperatura'],
            'leads'               => ['/api/v1/leads'],
            'clientes-crm'        => ['/api/v1/clientes-crm/12345678'],
        ];
    }

    /**
     * @dataProvider rutasProtegidas
     */
    public function test_rol_agente_recibe_403_en_recursos_operativos(string $uri): void
    {
        $agente = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($agente, 'sanctum')
            ->getJson($uri)
            ->assertForbidden();
    }

    /**
     * Subconjunto para el chequeo positivo: excluye endpoints cuyo 403 proviene de
     * una regla de negocio del controller (no de EnsureRole). `bipay/cajero/estado`
     * responde 403 vía contextoCajero() cuando la tienda no tiene cuenta Bipay
     * vinculada, algo ajeno a la capa de rol de SEC-03.
     *
     * @return array<string, array{0: string}>
     */
    public static function rutasProtegidasSinGateNegocio(): array
    {
        $rutas = self::rutasProtegidas();
        unset($rutas['bipay cajero estado']);

        return $rutas;
    }

    /**
     * @dataProvider rutasProtegidasSinGateNegocio
     */
    public function test_rol_tienda_no_es_rechazado_por_la_capa_de_rol(string $uri): void
    {
        // vendedor() -> rol 'vendedor', que EnsureRole trata como alias de 'tienda'.
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $status = $this->actingAs($tienda, 'sanctum')
            ->getJson($uri)
            ->status();

        // No exigimos 200 (el controller puede devolver 422/404/vacío según datos),
        // pero SÍ que la capa de rol no lo bloquee con 403.
        $this->assertNotSame(403, $status, "El rol tienda no debería recibir 403 en {$uri}");
    }

    public function test_rol_agente_recibe_403_al_crear_chip(): void
    {
        $agente = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/chips', [])
            ->assertForbidden();
    }

    public function test_rol_agente_recibe_403_al_crear_traslado(): void
    {
        $agente = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/traslados', [])
            ->assertForbidden();
    }

    public function test_rol_agente_recibe_403_al_escribir_crm(): void
    {
        $agente = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/clientes-crm', [])
            ->assertForbidden();
    }
}
