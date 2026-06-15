<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tienda_no_puede_administrar_usuarios_ni_planilla(): void
    {
        $usuario = $this->vendedorVinculado();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/usuarios')
            ->assertForbidden();

        $this->getJson('/api/v1/planilla/2026-06')
            ->assertForbidden();
    }

    public function test_store_reporte_ignora_identidad_y_tienda_falsificadas(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        DB::table('agentes')->insert([
            'id' => 9001,
            'nombres' => 'Agente objetivo',
            'estado' => 'ACTIVO',
            'tienda_base' => 'TACDA13',
            'dni' => '87654321',
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload([
                'agente_id' => 9001,
                'usuario_id' => 999999,
                'tienda_id' => 'TACDA13',
                'ventas' => [[
                    'vendedor_id' => $usuario->agente_id,
                    'tipo_venta' => 'OTROS_FLUJO',
                    'monto_total' => 10,
                    'efectivo_inicial' => 10,
                ]],
            ]))
            ->assertCreated();

        $reporteId = $response->json('id');
        $this->assertDatabaseHas('reportes', [
            'id' => $reporteId,
            'agente_id' => $usuario->agente_id,
            'usuario_id' => $usuario->id,
            'tienda_id' => 'PUNDA50',
        ]);
        $this->assertDatabaseHas('ventas', [
            'reporte_id' => $reporteId,
            'vendedor_id' => $usuario->agente_id,
        ]);
    }

    public function test_tienda_sin_turno_abierto_no_puede_operar_reportes(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        DB::table('asistencias')
            ->where('agente_id', $usuario->agente_id)
            ->update(['hora_salida' => '18:00:00']);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'OPEN_SHIFT_REQUIRED');
    }

    public function test_verify_pin_exige_agente_activo_y_turno_abierto(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        DB::table('agentes')->where('id', $usuario->agente_id)->update([
            'pin_seguridad' => Hash::make('2468'),
        ]);
        $dni = DB::table('agentes')->where('id', $usuario->agente_id)->value('dni');

        $this->postJson('/api/v1/auth/verify-pin', ['dni' => $dni, 'pin' => '2468'])
            ->assertOk()
            ->assertJsonPath('agente_id', $usuario->agente_id);

        DB::table('asistencias')
            ->where('agente_id', $usuario->agente_id)
            ->update(['hora_salida' => '18:00:00']);

        $this->postJson('/api/v1/auth/verify-pin', ['dni' => $dni, 'pin' => '2468'])
            ->assertForbidden()
            ->assertJsonPath('code', 'OPEN_SHIFT_REQUIRED');
    }

    public function test_inventario_de_tienda_no_expone_registros_ajenos(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $propio = $this->crearInventario('PUNDA50', 'Propio');
        $ajeno = $this->crearInventario('TACDA13', 'Ajeno');

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/inventario?tienda=TACDA13')
            ->assertOk()
            ->assertJsonPath('data.0.id', $propio)
            ->assertJsonMissing(['id' => $ajeno]);

        $this->getJson("/api/v1/inventario/{$ajeno}")
            ->assertForbidden();
    }

    public function test_ticket_ignora_identidad_falsificada_y_no_expone_otra_tienda(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'tienda_id' => 'TACDA13',
                'agente_id' => 999999,
                'vendedor' => 'Intruso',
                'monto' => 10,
                'efectivo' => 10,
                'descripcion' => 'Pago de prueba',
            ])
            ->assertOk();

        $this->assertDatabaseHas('tickets_emitidos', [
            'id' => $response->json('id'),
            'tienda_id' => 'PUNDA50',
            'agente_id' => $usuario->agente_id,
            'vendedor' => $usuario->nombre,
        ]);

        $ajeno = DB::table('tickets_emitidos')->insertGetId([
            'tienda_id' => 'TACDA13',
            'agente_id' => 9001,
            'vendedor' => 'Otra tienda',
            'descripcion' => 'Ticket ajeno',
            'monto' => 10,
            'cantidad' => 1,
            'creado_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/tickets/{$ajeno}")
            ->assertForbidden();
    }

    public function test_usuario_no_puede_leer_operar_ni_eliminar_reporte_ajeno(): void
    {
        $propietario = $this->vendedorVinculado('PUNDA50');
        $intruso = $this->vendedorVinculado('PUNDA50');
        $reporteId = $this->crearReporte($propietario);

        $this->actingAs($intruso, 'sanctum')
            ->getJson("/api/v1/reportes/{$reporteId}")
            ->assertForbidden();
        $this->postJson("/api/v1/reportes/{$reporteId}/solicitar-edicion", [
            'motivo_edicion' => 'Intento ajeno',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/reportes/{$reporteId}")
            ->assertForbidden();

        $this->assertDatabaseHas('reportes', ['id' => $reporteId]);
    }

    public function test_destroy_restaura_stock_antes_de_eliminar_reporte(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $inventarioId = DB::table('inventario_tiendas')->insertGetId([
            'tienda_id' => 'PUNDA50',
            'tipo' => 'EQUIPO',
            'producto_nombre' => 'Equipo de prueba',
            'cantidad' => 1,
            'estado' => 'DISPONIBLE',
            'precio_costo' => 100,
            'precio_minimo' => 120,
            'precio_normal' => 150,
            'fecha_registro' => now(),
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload([
                'efectivo_entregado' => 150,
                'ventas' => [[
                    'vendedor_id' => $usuario->agente_id,
                    'tipo_venta' => 'EQUIPO',
                    'monto_total' => 150,
                    'efectivo_inicial' => 150,
                    'producto_nombre' => 'Equipo de prueba',
                    'inventario_tienda_id' => $inventarioId,
                    'tipo_pago' => 'CONTADO',
                    'precio_venta' => 150,
                    'costo_snap' => 100,
                ]],
            ]))
            ->assertCreated();

        $reporteId = $response->json('id');
        $this->assertSame(0, (int) DB::table('inventario_tiendas')->where('id', $inventarioId)->value('cantidad'));

        $this->deleteJson("/api/v1/reportes/{$reporteId}")
            ->assertNoContent();

        $this->assertSame(1, (int) DB::table('inventario_tiendas')->where('id', $inventarioId)->value('cantidad'));
        $this->assertDatabaseMissing('reportes', ['id' => $reporteId]);
        $this->assertDatabaseMissing('ventas', ['reporte_id' => $reporteId]);
    }

    private function crearReporte(Usuario $usuario): int
    {
        return DB::table('reportes')->insertGetId([
            'agente_id' => $usuario->agente_id,
            'usuario_id' => $usuario->id,
            'tienda_id' => $usuario->tienda_id,
            'fecha' => '2026-06-15',
            'total_dia' => 0,
            'total_calculado' => 0,
            'estado' => 'borrador',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'agente_id' => 999999,
            'usuario_id' => 999999,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-15',
            'caja_inicial' => 0,
            'efectivo_entregado' => 0,
            'ventas' => [],
        ], $overrides);
    }

    private function crearInventario(string $tienda, string $nombre): int
    {
        return DB::table('inventario_tiendas')->insertGetId([
            'tienda_id' => $tienda,
            'tipo' => 'EQUIPO',
            'producto_nombre' => $nombre,
            'cantidad' => 1,
            'estado' => 'DISPONIBLE',
            'precio_costo' => 100,
            'precio_minimo' => 120,
            'precio_normal' => 150,
            'fecha_registro' => now(),
        ]);
    }
}
