<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de TicketController::show() y ::update(): ambos
 * repetian el mismo guard `$request->user()->rol !== 'admin' && $ticket->tienda_id
 * !== $request->user()->tienda_id`. La columna tickets_emitidos.tienda_id es NOT NULL
 * con default '', asi que el equivalente de "sin tienda" es cadena vacia: con un
 * ticket sin tienda ('') y un no-admin sin tienda ('') el guard no bloqueaba.
 */
class TicketShowUpdateAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearTicket(string $tienda): int
    {
        return DB::table('tickets_emitidos')->insertGetId([
            'tienda_id'   => $tienda,
            'descripcion' => 'Venta mostrador',
            'monto'       => 50,
            'nombre_cliente' => 'Cliente Original',
            'creado_en'   => now(),
        ]);
    }

    public function test_admin_puede_ver_y_editar_cualquier_ticket(): void
    {
        $id = $this->crearTicket('PUNDA50');
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/tickets/{$id}")->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/tickets/{$id}", ['nombre_cliente' => 'Editado Admin'])
            ->assertOk();

        $this->assertSame('Editado Admin', DB::table('tickets_emitidos')->find($id)->nombre_cliente);
    }

    public function test_usuario_puede_ver_y_editar_ticket_de_su_propia_tienda(): void
    {
        $id = $this->crearTicket('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($usuario, 'sanctum')->getJson("/api/v1/tickets/{$id}")->assertOk();

        $this->actingAs($usuario, 'sanctum')
            ->patchJson("/api/v1/tickets/{$id}", ['nombre_cliente' => 'Editado Propio'])
            ->assertOk();

        $this->assertSame('Editado Propio', DB::table('tickets_emitidos')->find($id)->nombre_cliente);
    }

    public function test_usuario_de_otra_tienda_recibe_403_y_no_edita(): void
    {
        $id = $this->crearTicket('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->actingAs($usuario, 'sanctum')->getJson("/api/v1/tickets/{$id}")->assertForbidden();

        $this->actingAs($usuario, 'sanctum')
            ->patchJson("/api/v1/tickets/{$id}", ['nombre_cliente' => 'Intento Ajeno'])
            ->assertForbidden();

        $this->assertSame('Cliente Original', DB::table('tickets_emitidos')->find($id)->nombre_cliente);
    }

    public function test_usuario_sin_tienda_y_ticket_sin_tienda_recibe_403_y_no_edita(): void
    {
        $id = $this->crearTicket('');
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $this->actingAs($usuarioSinTienda, 'sanctum')->getJson("/api/v1/tickets/{$id}")->assertForbidden();

        $this->actingAs($usuarioSinTienda, 'sanctum')
            ->patchJson("/api/v1/tickets/{$id}", ['nombre_cliente' => 'Intento Huerfano'])
            ->assertForbidden();

        $this->assertSame('Cliente Original', DB::table('tickets_emitidos')->find($id)->nombre_cliente);
    }

    public function test_editar_forma_de_pago_queda_auditado_en_log(): void
    {
        Log::spy();

        $id = $this->crearTicket('PUNDA50');
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/tickets/{$id}", [
                'forma_pago' => 'Yape',
                'efectivo' => 0,
                'yape' => 50,
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('ticket.forma_pago_actualizada', \Mockery::on(
                fn (array $context) => $context['ticket_id'] === $id
                    && $context['usuario_id'] === $admin->id
                    && $context['antes']['forma_pago'] === 'Efectivo'
                    && $context['despues']['forma_pago'] === 'Yape'
                    && (float) $context['despues']['yape'] === 50.0
            ));
    }
}
