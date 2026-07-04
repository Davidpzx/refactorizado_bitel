<?php

namespace Tests\Feature;

use App\Services\Crm\TemperaturaCalculator;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmTemperaturaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;
    private TemperaturaCalculator $calculador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario   = Usuario::factory()->create();
        $this->calculador = new TemperaturaCalculator();
    }

    private function crearCliente(string $dni, array $overrides = []): int
    {
        return (int) DB::table('crm_clientes')->insertGetId(array_merge([
            'dni'            => $dni,
            'nombres'        => 'Cliente',
            'apellidos'      => 'De Prueba',
            'telefono'       => '987654321',
            'operadora'      => 'Bitel',
            'fecha_registro' => now(),
        ], $overrides));
    }

    private function crearInteraccion(int $clienteId, array $overrides = []): void
    {
        DB::table('crm_interacciones')->insert(array_merge([
            'cliente_id'     => $clienteId,
            'tienda_codigo'  => 'PUNDA50',
            'agente_nombre'  => 'Agente Test',
            'tipo_operacion' => 'Postpago',
            'fecha_hora'     => now(),
        ], $overrides));
    }

    // ── Reglas del calculador ────────────────────────────────────────────────────

    public function test_calculador_devuelve_caliente_con_interaccion_reciente_sin_rechazo(): void
    {
        $id = $this->crearCliente('11111111');
        $this->crearInteraccion($id, ['fecha_hora' => now()->subHours(10), 'motivo_rechazo' => null]);

        $resultado = $this->calculador->calcular('11111111');

        $this->assertEquals('Caliente', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_neutro_si_interaccion_reciente_supera_48_horas(): void
    {
        $id = $this->crearCliente('22222222');
        $this->crearInteraccion($id, ['fecha_hora' => now()->subHours(49), 'motivo_rechazo' => null]);

        $resultado = $this->calculador->calcular('22222222');

        $this->assertEquals('Neutro', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_frio_con_rechazo_evaluacion_crediticia_antiguo(): void
    {
        $id = $this->crearCliente('33333333');
        $this->crearInteraccion($id, [
            'fecha_hora'     => now()->subMonths(6),
            'motivo_rechazo' => 'Evaluación Crediticia',
        ]);

        $resultado = $this->calculador->calcular('33333333');

        $this->assertEquals('Frío', $resultado['etiqueta']);
    }

    public function test_calculador_prioriza_caliente_sobre_frio_si_ambas_condiciones_matchean(): void
    {
        $id = $this->crearCliente('44444444');
        $this->crearInteraccion($id, [
            'fecha_hora'     => now()->subMonths(6),
            'motivo_rechazo' => 'Evaluación Crediticia',
        ]);
        $this->crearInteraccion($id, [
            'fecha_hora'     => now()->subHours(5),
            'motivo_rechazo' => null,
        ]);

        $resultado = $this->calculador->calcular('44444444');

        $this->assertEquals('Caliente', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_upselling_con_consulta_prepago_de_mas_de_30_dias(): void
    {
        $id = $this->crearCliente('55555555');
        $this->crearInteraccion($id, [
            'tipo_operacion' => 'Prepago',
            'fecha_hora'     => now()->subDays(31),
            'motivo_rechazo' => null,
        ]);

        $resultado = $this->calculador->calcular('55555555');

        $this->assertEquals('Upselling', $resultado['etiqueta']);
    }

    public function test_calculador_no_marca_upselling_si_consulta_prepago_es_reciente(): void
    {
        $id = $this->crearCliente('66666666');
        $this->crearInteraccion($id, [
            'tipo_operacion' => 'Prepago',
            'fecha_hora'     => now()->subDays(10),
            'motivo_rechazo' => null,
        ]);

        $resultado = $this->calculador->calcular('66666666');

        $this->assertEquals('Neutro', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_neutro_sin_interacciones(): void
    {
        $this->crearCliente('77777777');

        $resultado = $this->calculador->calcular('77777777');

        $this->assertEquals('Neutro', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_neutro_si_dni_no_existe(): void
    {
        $resultado = $this->calculador->calcular('99999999');

        $this->assertEquals('Neutro', $resultado['etiqueta']);
    }

    // ── Endpoints ────────────────────────────────────────────────────────────────

    public function test_endpoint_temperatura_sin_autenticar_devuelve_401(): void
    {
        $this->getJson('/api/v1/crm/temperatura')->assertStatus(401);
    }

    public function test_endpoint_lista_temperatura_devuelve_estructura_y_filtra_por_tienda(): void
    {
        $id1 = $this->crearCliente('12345678');
        $id2 = $this->crearCliente('87654321');
        $this->crearInteraccion($id1, ['tienda_codigo' => 'PUNDA50']);
        $this->crearInteraccion($id2, ['tienda_codigo' => 'TACDA13']);

        $response = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/crm/temperatura?tienda_codigo=PUNDA50')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'dni', 'nombres', 'apellidos', 'tienda_codigo', 'temperatura' => ['etiqueta', 'bg', 'text', 'border']],
                ],
                'total', 'current_page', 'per_page',
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('12345678', $data[0]['dni']);
    }

    public function test_endpoint_temperatura_por_dni_devuelve_solo_el_objeto_temperatura(): void
    {
        $id = $this->crearCliente('10101010');
        $this->crearInteraccion($id, ['fecha_hora' => now()->subHours(2), 'motivo_rechazo' => null]);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/crm/temperatura/10101010')
            ->assertOk()
            ->assertJson(['etiqueta' => 'Caliente']);
    }

    public function test_endpoint_lista_temperatura_filtra_antes_de_paginar_y_total_es_consistente(): void
    {
        // 2 clientes "Caliente" (interaccion reciente sin rechazo) + 1 "Frio" (rechazo por credito, antiguo).
        $caliente1 = $this->crearCliente('20000001');
        $caliente2 = $this->crearCliente('20000002');
        $frio      = $this->crearCliente('20000003');

        $this->crearInteraccion($caliente1, ['fecha_hora' => now()->subHours(1), 'motivo_rechazo' => null]);
        $this->crearInteraccion($caliente2, ['fecha_hora' => now()->subHours(2), 'motivo_rechazo' => null]);
        $this->crearInteraccion($frio, [
            'fecha_hora'     => now()->subMonths(6),
            'motivo_rechazo' => 'Evaluación Crediticia',
        ]);

        // per_page=1 fuerza a que, si el filtro se aplicara despues de paginar, la
        // pagina cruda (ordenada por fecha_hora desc) solo traeria a caliente1.
        $response = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/crm/temperatura?temperatura=Caliente&per_page=1&page=1')
            ->assertOk();

        $this->assertEquals(2, $response->json('total'));
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Caliente', $response->json('data.0.temperatura.etiqueta'));

        $pagina2 = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/crm/temperatura?temperatura=Caliente&per_page=1&page=2')
            ->assertOk();

        $this->assertEquals(2, $pagina2->json('total'));
        $this->assertCount(1, $pagina2->json('data'));
        $this->assertEquals('Caliente', $pagina2->json('data.0.temperatura.etiqueta'));
        $this->assertNotEquals($response->json('data.0.dni'), $pagina2->json('data.0.dni'));
    }

    public function test_calculador_devuelve_caliente_exactamente_en_el_limite_de_48_horas(): void
    {
        // Congelamos el reloj: el "ahora" del calculador debe ser el mismo que el usado
        // para sembrar fecha_hora, para que el borde de >= 48h no dependa de milisegundos.
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $id = $this->crearCliente('30000001');
        $this->crearInteraccion($id, ['fecha_hora' => now()->subHours(48), 'motivo_rechazo' => null]);

        $resultado = $this->calculador->calcular('30000001');

        Carbon::setTestNow();

        $this->assertEquals('Caliente', $resultado['etiqueta']);
    }

    public function test_calculador_devuelve_upselling_exactamente_en_el_limite_de_30_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $id = $this->crearCliente('30000002');
        $this->crearInteraccion($id, [
            'tipo_operacion' => 'Prepago',
            'fecha_hora'     => now()->subDays(30),
            'motivo_rechazo' => null,
        ]);

        $resultado = $this->calculador->calcular('30000002');

        Carbon::setTestNow();

        $this->assertEquals('Upselling', $resultado['etiqueta']);
    }
}
