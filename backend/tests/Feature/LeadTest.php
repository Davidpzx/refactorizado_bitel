<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::factory()->create();

        // Agente mínimo para que el validator exists:agentes,id funcione
        DB::table('agentes')->insert([
            'id'         => 1,
            'nombres'    => 'Agente Test',
            'estado'     => 'ACTIVO',
            'tienda_base'=> 'PUNDA50',
            'sueldo_base'=> 1200.00,
        ]);
    }

    // ── Pipeline ─────────────────────────────────────────────────────────────────

    public function test_pipeline_sin_autenticar_devuelve_401(): void
    {
        $this->getJson('/api/v1/crm/pipeline')->assertStatus(401);
    }

    public function test_pipeline_devuelve_estructura_correcta(): void
    {
        Lead::create([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'estado'    => 'NUEVO',
            'fuente'    => 'PRESENCIAL',
        ]);

        Lead::create([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'estado'    => 'CONVERTIDO',
            'fuente'    => 'WHATSAPP',
        ]);

        $response = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/crm/pipeline')
            ->assertOk()
            ->assertJsonStructure([
                'pipeline' => [
                    ['estado', 'total'],
                ],
                'total_leads',
                'tasa_conversion',
            ]);

        $data = $response->json();
        $this->assertEquals(2, $data['total_leads']);
        $this->assertEquals(50.0, $data['tasa_conversion']); // 1 CONVERTIDO / 2 total = 50%
    }

    // ── Index ────────────────────────────────────────────────────────────────────

    public function test_index_sin_autenticar_devuelve_401(): void
    {
        $this->getJson('/api/v1/leads')->assertStatus(401);
    }

    public function test_index_devuelve_leads_paginados(): void
    {
        Lead::create(['agente_id' => 1, 'tienda_id' => 'PUNDA50', 'estado' => 'NUEVO', 'fuente' => 'PRESENCIAL']);
        Lead::create(['agente_id' => 1, 'tienda_id' => 'PUNDA50', 'estado' => 'CONTACTADO', 'fuente' => 'LLAMADA']);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total', 'per_page']);
    }

    public function test_index_filtra_por_texto_libre_en_cliente(): void
    {
        DB::table('clientes')->insert([
            ['id' => 1, 'dni_ruc' => '12345678', 'nombre' => 'Juan Perez', 'telefono' => '987654321'],
            ['id' => 2, 'dni_ruc' => '87654321', 'nombre' => 'Maria Lopez', 'telefono' => '999888777'],
        ]);
        Lead::create(['cliente_id' => 1, 'agente_id' => 1, 'tienda_id' => 'PUNDA50', 'estado' => 'NUEVO', 'fuente' => 'PRESENCIAL']);
        Lead::create(['cliente_id' => 2, 'agente_id' => 1, 'tienda_id' => 'PUNDA50', 'estado' => 'NUEVO', 'fuente' => 'PRESENCIAL']);

        $response = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/leads?q=Perez')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(1, $data[0]['cliente_id']);
    }

    public function test_index_filtra_por_texto_libre_en_nombre_de_agente(): void
    {
        DB::table('agentes')->insert([
            'id' => 2, 'nombres' => 'Carlos Ramirez', 'estado' => 'ACTIVO', 'tienda_base' => 'PUNDA50', 'sueldo_base' => 1200.00,
        ]);
        Lead::create(['agente_id' => 1, 'tienda_id' => 'PUNDA50', 'estado' => 'NUEVO', 'fuente' => 'PRESENCIAL']);
        Lead::create(['agente_id' => 2, 'tienda_id' => 'PUNDA50', 'estado' => 'NUEVO', 'fuente' => 'PRESENCIAL']);

        $response = $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/leads?q=Ramirez')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(2, $data[0]['agente_id']);
    }

    // ── Store ────────────────────────────────────────────────────────────────────

    public function test_store_crea_lead_correctamente(): void
    {
        $this->actingAs($this->usuario, 'sanctum')
            ->postJson('/api/v1/leads', [
                'agente_id' => 1,
                'tienda_id' => 'PUNDA50',
                'estado'    => 'NUEVO',
                'fuente'    => 'PRESENCIAL',
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['estado' => 'NUEVO', 'tienda_id' => 'PUNDA50']);

        $this->assertDatabaseHas('leads', ['agente_id' => 1, 'tienda_id' => 'PUNDA50']);
    }

    public function test_store_sin_campos_requeridos_devuelve_422(): void
    {
        $this->actingAs($this->usuario, 'sanctum')
            ->postJson('/api/v1/leads', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agente_id', 'tienda_id']);
    }

    public function test_store_estado_invalido_devuelve_422(): void
    {
        $this->actingAs($this->usuario, 'sanctum')
            ->postJson('/api/v1/leads', [
                'agente_id' => 1,
                'tienda_id' => 'PUNDA50',
                'estado'    => 'INEXISTENTE',
                'fuente'    => 'PRESENCIAL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    // ── Update ───────────────────────────────────────────────────────────────────

    public function test_update_cambia_estado_del_lead(): void
    {
        $lead = Lead::create([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'estado'    => 'NUEVO',
            'fuente'    => 'PRESENCIAL',
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->putJson("/api/v1/leads/{$lead->id}", ['estado' => 'CONTACTADO'])
            ->assertOk()
            ->assertJsonFragment(['estado' => 'CONTACTADO']);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'CONTACTADO']);
    }

    // ── Destroy ──────────────────────────────────────────────────────────────────

    public function test_destroy_elimina_lead(): void
    {
        $lead = Lead::create([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'estado'    => 'PERDIDO',
            'fuente'    => 'LLAMADA',
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->deleteJson("/api/v1/leads/{$lead->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }
}
