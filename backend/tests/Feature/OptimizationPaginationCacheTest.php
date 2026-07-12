<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OptimizationPaginationCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_chips_conserva_shape_legacy_con_cap_y_ofrece_paginacion_limitada(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $tiendaId = DB::table('tiendas')->insertGetId(['codigo' => 'OPT07', 'nombre' => 'OPT 07']);
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [
                'tienda_id' => $tiendaId,
                'tienda_origen' => 'LOTE-'.$i,
                'tipo_chip' => 'FISICO',
                'stock_actual' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('inventario_chips')->insert($chunk);
        }

        $legacy = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/chips')->assertOk();
        $this->assertCount(500, $legacy->json('data'));
        $this->assertArrayNotHasKey('current_page', $legacy->json());

        $paginado = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/chips?page=1&per_page=999')
            ->assertOk()->assertJsonPath('per_page', 200)->assertJsonPath('total', 501);
        $this->assertCount(200, $paginado->json('data'));

        $inventario = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario-chips?page=1&per_page=999')
            ->assertOk()->assertJsonPath('per_page', 200);
        $this->assertCount(200, $inventario->json('data'));
    }

    public function test_interacciones_usan_cursor_compuesto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert(['id' => 1, 'nombres' => 'A', 'estado' => 'ACTIVO']);
        $lead = Lead::create(['agente_id' => 1, 'tienda_id' => 'T', 'estado' => 'NUEVO', 'fuente' => 'LLAMADA']);
        foreach ([1, 2, 3] as $id) {
            DB::table('interacciones_crm')->insert([
                'id' => $id, 'lead_id' => $lead->id, 'agente_id' => 1, 'tipo' => 'LLAMADA',
                'fecha' => '2026-07-12 10:00:00',
            ]);
        }

        $first = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/leads/'.$lead->id.'/interacciones?limit=2')
            ->assertOk()->assertJsonPath('per_page', 2);
        $this->assertSame([3, 2], array_column($first->json('data'), 'id'));

        $cursor = urlencode($first->json('next_cursor'));
        $second = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/leads/'.$lead->id.'/interacciones?limit=2&cursor='.$cursor)
            ->assertOk();
        $this->assertSame([1], array_column($second->json('data'), 'id'));
    }

    public function test_cache_de_planes_se_invalida_al_mutar(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/comisiones-planes')
            ->assertOk()->assertJsonCount(0);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/comisiones-planes', [
            'tipo_servicio' => 'POSTPAGO',
            'nombre_plan' => 'Plan cache fresco',
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/comisiones-planes')
            ->assertOk()->assertJsonFragment(['nombre_plan' => 'Plan cache fresco']);
    }
}
