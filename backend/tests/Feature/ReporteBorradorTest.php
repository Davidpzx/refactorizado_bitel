<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReporteBorradorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-11 15:00:00', 'America/Lima'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guarda_y_actualiza_un_unico_borrador_por_usuario_tienda_y_fecha(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');

        $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/reportes/borrador', [
                'fecha' => '2026-06-11',
                'caja_inicial' => 50,
                'ventas' => [['tipo_venta' => 'POSTPAGO']],
                '_csrf' => 'ignorar',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'accion' => 'creado',
            ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes/borrador', [
                'fecha' => '2026-06-11',
                'caja_inicial' => 80,
                'ventas' => [],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'accion' => 'actualizado',
                'borrador_id' => null,
            ]);

        $this->assertDatabaseCount('reportes_borradores', 1);

        $datos = json_decode(
            DB::table('reportes_borradores')->value('datos_json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame(80, $datos['caja_inicial']);
        $this->assertArrayNotHasKey('_csrf', $datos);
    }

    public function test_recupera_primero_el_borrador_propio_y_hace_fallback_a_la_misma_tienda(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $companero = $this->vendedorVinculado('PUNDA50');

        DB::table('reportes_borradores')->insert([
            [
                'agente_id' => $companero->agente_id,
                'tienda_id' => 'PUNDA50',
                'fecha' => '2026-06-11',
                'datos_json' => json_encode(['origen' => 'companero']),
                'creado_en' => now()->subMinute(),
                'actualizado_en' => now()->subMinute(),
            ],
            [
                'agente_id' => $usuario->agente_id,
                'tienda_id' => 'PUNDA50',
                'fecha' => '2026-06-11',
                'datos_json' => json_encode(['origen' => 'propio']),
                'creado_en' => now()->subHour(),
                'actualizado_en' => now()->subHour(),
            ],
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/reportes/borrador')
            ->assertOk()
            ->assertJsonPath('borrador.origen', 'propio')
            ->assertJsonPath('borrador._cloud_agente', $usuario->agente_id)
            ->assertJsonPath('borrador._mismo_usuario', true);

        DB::table('reportes_borradores')
            ->where('agente_id', $usuario->agente_id)
            ->delete();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/reportes/borrador')
            ->assertOk()
            ->assertJsonPath('borrador.origen', 'companero')
            ->assertJsonPath('borrador._mismo_usuario', false);
    }

    public function test_no_expone_borradores_de_otra_tienda(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $otro = $this->vendedorVinculado('TACDA13');

        DB::table('reportes_borradores')->insert([
            'agente_id' => $otro->agente_id,
            'tienda_id' => 'TACDA13',
            'fecha' => '2026-06-11',
            'datos_json' => json_encode(['secreto' => true]),
            'creado_en' => now(),
            'actualizado_en' => now(),
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/reportes/borrador')
            ->assertOk()
            ->assertJsonPath('borrador', null);
    }

    public function test_elimina_solo_el_borrador_propio_del_dia(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $companero = $this->vendedorVinculado('PUNDA50');

        foreach ([$usuario, $companero] as $propietario) {
            DB::table('reportes_borradores')->insert([
                'agente_id' => $propietario->agente_id,
                'tienda_id' => 'PUNDA50',
                'fecha' => '2026-06-11',
                'datos_json' => '{}',
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        }

        $this->actingAs($usuario, 'sanctum')
            ->deleteJson('/api/v1/reportes/borrador')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('reportes_borradores', [
            'agente_id' => $usuario->agente_id,
        ]);
        $this->assertDatabaseHas('reportes_borradores', [
            'agente_id' => $companero->agente_id,
        ]);
    }

    public function test_acepta_el_formato_legacy_para_eliminar_por_post(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');

        DB::table('reportes_borradores')->insert([
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-11',
            'datos_json' => '{}',
            'creado_en' => now(),
            'actualizado_en' => now(),
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes/borrador?eliminar=1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('reportes_borradores', [
            'agente_id' => $usuario->agente_id,
        ]);
    }

    public function test_guardar_reporte_completo_limpia_el_borrador_del_usuario(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');

        DB::table('reportes_borradores')->insert([
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-11',
            'datos_json' => '{}',
            'creado_en' => now(),
            'actualizado_en' => now(),
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', [
                'agente_id' => $usuario->id,
                'tienda_id' => 'PUNDA50',
                'usuario_id' => $usuario->id,
                'fecha' => '2026-06-11',
                'caja_inicial' => 0,
                'efectivo_entregado' => 0,
                'ventas' => [],
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('reportes_borradores', [
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-11',
        ]);
    }

    public function test_requiere_autenticacion_y_tienda_asignada(): void
    {
        $this->postJson('/api/v1/reportes/borrador', ['campo' => 'valor'])
            ->assertUnauthorized();

        $admin = Usuario::factory()->admin()->create(['tienda_id' => null]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/reportes/borrador', ['campo' => 'valor'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El usuario autenticado no tiene una tienda asignada.');
    }
}
