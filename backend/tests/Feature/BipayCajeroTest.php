<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BipayCajeroTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 10, 0, 0, 'America/Lima'));
        $this->crearTablasBipay();

        DB::table('cuentas_bipay')->insert([
            'id' => 1,
            'alias' => 'Razón Social Uno',
            'umbral_alerta' => 100,
            'saldo_actual' => 500,
            'saldo_bipay' => 300,
            'saldo_anypay' => 200,
        ]);

        DB::table('tiendas')->insert([
            [
                'codigo' => 'PUNDA01',
                'nombre' => 'Tienda Uno',
                'activo' => 1,
                'cuenta_bipay_id' => 1,
            ],
            [
                'codigo' => 'PUNDA02',
                'nombre' => 'Tienda Dos',
                'activo' => 1,
                'cuenta_bipay_id' => 1,
            ],
        ]);

        $this->cajero = Usuario::factory()->create([
            'rol' => 'tienda',
            'tienda_id' => 'PUNDA01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cajero_consulta_estado_de_su_cuenta_y_tiendas_relacionadas(): void
    {
        DB::table('bipay_saldos_dia')->insert([
            'tienda_codigo' => 'PUNDA01',
            'cuenta_bipay_id' => 1,
            'fecha' => '2026-06-11',
            'saldo_actual' => 90,
            'saldo_bipay_actual' => 50,
            'saldo_anypay_actual' => 40,
            'alerta_enviada' => 1,
            'actualizado_en' => '2026-06-11 09:59:00',
        ]);

        DB::table('bipay_cooldowns')->insert([
            'cuenta_bipay_id' => 1,
            'tienda_codigo' => 'PUNDA01',
            'cooldown_hasta' => '2026-06-11 10:03:00',
            'actualizado_en' => '2026-06-11 09:59:00',
        ]);

        $this->actingAs($this->cajero, 'sanctum')
            ->getJson('/api/v1/bipay/cajero/estado')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bipay_live', 300)
            ->assertJsonPath('anypay_live', 200)
            ->assertJsonPath('bipay_actual', 50)
            ->assertJsonPath('anypay_actual', 40)
            ->assertJsonPath('alerta', true)
            ->assertJsonPath('cooldown_segs', 180)
            ->assertJsonCount(2, 'tiendas_estado');
    }

    public function test_cajero_actualiza_un_saldo_y_conserva_la_otra_plataforma(): void
    {
        DB::table('bipay_saldos_dia')->insert([
            'tienda_codigo' => 'PUNDA01',
            'cuenta_bipay_id' => 1,
            'fecha' => '2026-06-11',
            'saldo_actual' => 150,
            'saldo_bipay_actual' => 100,
            'saldo_anypay_actual' => 50,
            'alerta_enviada' => 0,
            'actualizado_en' => '2026-06-11 09:00:00',
        ]);

        $this->actingAs($this->cajero, 'sanctum')
            ->postJson('/api/v1/bipay/cajero/actualizar', [
                'saldo_bipay' => 40,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bipay_actual', 40)
            ->assertJsonPath('anypay_actual', null)
            ->assertJsonPath('bipay_live', 40)
            ->assertJsonPath('anypay_live', 50)
            ->assertJsonPath('alerta', true)
            ->assertJsonPath('cooldown_segs', 240);

        $this->assertDatabaseHas('cuentas_bipay', [
            'id' => 1,
            'saldo_bipay' => 40,
            'saldo_anypay' => 50,
            'saldo_actual' => 90,
        ]);
        $this->assertDatabaseHas('transacciones_bipay', [
            'cuenta_origen_id' => 1,
            'tienda_codigo' => 'PUNDA01',
            'tipo_operacion' => 'DECLARACION_DIA',
            'monto' => -60,
            'creado_por' => $this->cajero->id,
        ]);
        $this->assertDatabaseHas('bipay_cooldowns', [
            'cuenta_bipay_id' => 1,
            'tienda_codigo' => 'PUNDA01',
            'cooldown_hasta' => '2026-06-11 10:04:00',
        ]);

        $cooldownOtra = DB::table('bipay_cooldowns')
            ->where('cuenta_bipay_id', 1)
            ->where('tienda_codigo', 'PUNDA02')
            ->value('cooldown_hasta');

        $this->assertNotNull($cooldownOtra);
        $this->assertContains($cooldownOtra, [
            '2026-06-11 10:01:00',
            '2026-06-11 10:02:00',
            '2026-06-11 10:03:00',
        ]);
    }

    public function test_cajero_no_puede_actualizar_durante_su_cooldown(): void
    {
        DB::table('bipay_cooldowns')->insert([
            'cuenta_bipay_id' => 1,
            'tienda_codigo' => 'PUNDA01',
            'cooldown_hasta' => '2026-06-11 10:02:00',
            'actualizado_en' => '2026-06-11 09:59:00',
        ]);

        $this->actingAs($this->cajero, 'sanctum')
            ->postJson('/api/v1/bipay/cajero/actualizar', [
                'saldo_bipay' => 120,
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('cooldown', true)
            ->assertJsonPath('cooldown_segs', 120);

        $this->assertDatabaseCount('transacciones_bipay', 0);
    }

    public function test_cajero_cierra_usando_el_saldo_actual_de_la_plataforma_omitida(): void
    {
        DB::table('bipay_saldos_dia')->insert([
            'tienda_codigo' => 'PUNDA01',
            'cuenta_bipay_id' => 1,
            'fecha' => '2026-06-11',
            'saldo_actual' => 150,
            'saldo_bipay_actual' => 100,
            'saldo_anypay_actual' => 50,
            'alerta_enviada' => 0,
            'actualizado_en' => '2026-06-11 09:00:00',
        ]);
        DB::table('bipay_cooldowns')->insert([
            'cuenta_bipay_id' => 1,
            'tienda_codigo' => 'PUNDA01',
            'cooldown_hasta' => '2026-06-11 10:04:00',
            'actualizado_en' => '2026-06-11 10:00:00',
        ]);

        $this->actingAs($this->cajero, 'sanctum')
            ->postJson('/api/v1/bipay/cajero/cierre', [
                'saldo_anypay_cierre' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bipay_cierre', 100)
            ->assertJsonPath('anypay_cierre', 30);

        $this->assertDatabaseHas('bipay_saldos_dia', [
            'tienda_codigo' => 'PUNDA01',
            'fecha' => '2026-06-11',
            'saldo_bipay_cierre' => 100,
            'saldo_anypay_cierre' => 30,
            'saldo_cierre' => 130,
        ]);
        $this->assertDatabaseMissing('bipay_cooldowns', [
            'cuenta_bipay_id' => 1,
            'tienda_codigo' => 'PUNDA01',
        ]);
        $this->assertDatabaseHas('transacciones_bipay', [
            'tipo_operacion' => 'CIERRE_DIA',
            'tienda_codigo' => 'PUNDA01',
            'monto' => 130,
        ]);
    }

    public function test_usuario_admin_no_puede_usar_endpoints_de_cajero(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/cajero/estado')
            ->assertForbidden()
            ->assertJsonPath('ok', false);
    }

    public function test_transacciones_filtra_por_tipo_operacion(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('transacciones_bipay')->insert([
            ['cuenta_origen_id' => 1, 'monto' => 100, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'creado_en' => '2026-06-11 09:00:00'],
            ['cuenta_origen_id' => 1, 'monto' => 50,  'tipo_operacion' => 'AJUSTE',  'plataforma' => 'BIPAY', 'creado_en' => '2026-06-11 09:30:00'],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/transacciones?fecha_desde=2026-06-01&fecha_hasta=2026-06-30&tipo_operacion=AJUSTE')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('AJUSTE', $data[0]['tipo_operacion']);
    }

    private function crearTablasBipay(): void
    {
        Schema::create('cuentas_bipay', function (Blueprint $table) {
            $table->id();
            $table->string('alias');
            $table->decimal('umbral_alerta', 10, 2)->default(0);
            $table->decimal('saldo_actual', 10, 2)->default(0);
            $table->decimal('saldo_bipay', 10, 2)->default(0);
            $table->decimal('saldo_anypay', 10, 2)->default(0);
        });

        Schema::create('bipay_saldos_dia', function (Blueprint $table) {
            $table->id();
            $table->string('tienda_codigo', 20);
            $table->unsignedBigInteger('cuenta_bipay_id');
            $table->date('fecha');
            $table->decimal('saldo_actual', 10, 2)->nullable();
            $table->decimal('saldo_bipay_actual', 10, 2)->nullable();
            $table->decimal('saldo_anypay_actual', 10, 2)->nullable();
            $table->decimal('saldo_cierre', 10, 2)->nullable();
            $table->decimal('saldo_bipay_cierre', 10, 2)->nullable();
            $table->decimal('saldo_anypay_cierre', 10, 2)->nullable();
            $table->boolean('alerta_enviada')->default(false);
            $table->dateTime('actualizado_en');
            $table->unique(['tienda_codigo', 'cuenta_bipay_id', 'fecha']);
        });

        Schema::create('bipay_cooldowns', function (Blueprint $table) {
            $table->unsignedBigInteger('cuenta_bipay_id');
            $table->string('tienda_codigo', 20);
            $table->dateTime('cooldown_hasta');
            $table->dateTime('actualizado_en');
            $table->primary(['cuenta_bipay_id', 'tienda_codigo']);
        });

        Schema::create('transacciones_bipay', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cuenta_origen_id')->nullable();
            $table->unsignedBigInteger('cuenta_destino_id')->nullable();
            $table->decimal('monto', 10, 2);
            $table->string('tipo_operacion', 30);
            $table->string('plataforma', 20);
            $table->string('tienda_codigo', 20)->nullable();
            $table->decimal('saldo_origen_pre', 10, 2)->nullable();
            $table->decimal('saldo_anypay_pre', 10, 2)->nullable();
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->dateTime('creado_en');
        });
    }
}
