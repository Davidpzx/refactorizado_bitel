<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T1.2 — Panel "Jefe de Tienda" en GET /asistencias/mi-historial.
 * El jefe SOLO ve el equipo de su propia tienda (scoping fail-closed).
 */
class MiHistorialEquipoScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_solo_ve_agentes_de_su_propia_tienda(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $jefeId = (int) $usuario->agente_id;
        DB::table('agentes')->where('id', $jefeId)->update(['es_gerencia' => '1']);

        // Compañero de la MISMA tienda → debe aparecer.
        DB::table('agentes')->insert([
            'id' => 2001,
            'dni' => '00002001',
            'nombres' => 'Compañero misma tienda',
            'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50',
        ]);
        // Agente de OTRA tienda → NO debe aparecer.
        DB::table('agentes')->insert([
            'id' => 2002,
            'dni' => '00002002',
            'nombres' => 'Agente tienda ajena',
            'estado' => 'ACTIVO',
            'tienda_base' => 'OTRA99',
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/asistencias/mi-historial?fecha_desde='.now()->toDateString().'&fecha_hasta='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('agente.es_jefe', true);

        $ids = collect($response->json('equipo'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($ids->contains($jefeId), 'El jefe debe verse a sí mismo en el equipo.');
        $this->assertTrue($ids->contains(2001), 'Debe incluir al compañero de su misma tienda.');
        $this->assertFalse($ids->contains(2002), 'NO debe filtrar al agente de otra tienda.');
    }

    public function test_jefe_sin_tienda_base_no_ve_equipo(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $jefeId = (int) $usuario->agente_id;
        // Jefe sin tienda_base + agente huérfano con tienda null: fail-closed → equipo vacío.
        DB::table('agentes')->where('id', $jefeId)->update(['es_gerencia' => '1', 'tienda_base' => null]);
        DB::table('agentes')->insert([
            'id' => 2003,
            'dni' => '00002003',
            'nombres' => 'Agente sin tienda',
            'estado' => 'ACTIVO',
            'tienda_base' => null,
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/asistencias/mi-historial')
            ->assertOk()
            ->assertJsonPath('agente.es_jefe', true)
            ->assertJsonCount(0, 'equipo');
    }
}
