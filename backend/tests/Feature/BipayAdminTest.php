<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BipayAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cuentas_bipay', function (Blueprint $t) {
            $t->id();
            $t->string('alias', 100);
            $t->decimal('saldo_bipay', 12, 2)->default(0);
            $t->decimal('saldo_anypay', 12, 2)->default(0);
            $t->decimal('saldo_actual', 12, 2)->default(0);
        });
        Schema::create('transacciones_bipay', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cuenta_origen_id')->nullable();
            $t->unsignedBigInteger('cuenta_destino_id')->nullable();
            $t->string('tipo_operacion', 30);
            $t->string('plataforma', 20)->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->decimal('saldo_origen_pre', 12, 2)->nullable();
            $t->decimal('saldo_anypay_pre', 12, 2)->nullable();
            $t->string('observacion', 255)->nullable();
            $t->unsignedBigInteger('creado_por')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function test_transferencia_mueve_saldo_y_registra(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $a = DB::table('cuentas_bipay')->insertGetId(['alias' => 'A', 'saldo_bipay' => 100, 'saldo_anypay' => 0, 'saldo_actual' => 100]);
        $b = DB::table('cuentas_bipay')->insertGetId(['alias' => 'B', 'saldo_bipay' => 0, 'saldo_anypay' => 0, 'saldo_actual' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bipay/transferir', ['cuenta_origen_id' => $a, 'cuenta_destino_id' => $b, 'monto' => 50])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(50.0, (float) DB::table('cuentas_bipay')->where('id', $a)->value('saldo_actual'));
        $this->assertSame(50.0, (float) DB::table('cuentas_bipay')->where('id', $b)->value('saldo_actual'));
        $this->assertDatabaseHas('transacciones_bipay', ['tipo_operacion' => 'TRANSFERENCIA']);
    }

    public function test_ajuste_setea_saldo_y_registra(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $c = DB::table('cuentas_bipay')->insertGetId(['alias' => 'C', 'saldo_bipay' => 100, 'saldo_anypay' => 0, 'saldo_actual' => 100]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bipay/ajustar', ['cuenta_id' => $c, 'saldo_bipay' => 10, 'saldo_anypay' => 5, 'motivo' => 'conteo fisico'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(15.0, (float) DB::table('cuentas_bipay')->where('id', $c)->value('saldo_actual'));
        $this->assertDatabaseHas('transacciones_bipay', ['tipo_operacion' => 'AJUSTE']);
    }

    public function test_no_admin_rechazado(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $this->actingAs($vendedor, 'sanctum')
            ->postJson('/api/v1/bipay/transferir', ['cuenta_origen_id' => 1, 'cuenta_destino_id' => 2, 'monto' => 10])
            ->assertStatus(403);
    }
}
