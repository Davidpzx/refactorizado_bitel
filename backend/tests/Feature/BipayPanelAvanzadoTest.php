<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BipayPanelAvanzadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'America/Lima'));

        Schema::create('cuentas_bipay', function (Blueprint $t) {
            $t->id();
            $t->string('alias', 100);
            $t->string('tipo', 10)->default('MADRE');
            $t->string('numero_cuenta', 60)->nullable();
            $t->string('razon_social', 150)->nullable();
            $t->unsignedBigInteger('cuenta_madre_id')->nullable();
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
            $t->string('tienda_codigo', 20)->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->decimal('saldo_origen_pre', 12, 2)->nullable();
            $t->decimal('saldo_anypay_pre', 12, 2)->nullable();
            $t->text('observacion')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable();
            $t->dateTime('creado_en');
        });

        Schema::create('bipay_cooldowns', function (Blueprint $t) {
            $t->unsignedBigInteger('cuenta_bipay_id');
            $t->string('tienda_codigo', 20);
            $t->dateTime('cooldown_hasta');
            $t->dateTime('actualizado_en');
            $t->primary(['cuenta_bipay_id', 'tienda_codigo']);
        });

        if (! Schema::hasColumn('tiendas', 'cuenta_bipay_id')) {
            Schema::table('tiendas', fn (Blueprint $t) => $t->unsignedBigInteger('cuenta_bipay_id')->nullable());
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Gap 1: cuentas huérfanas ─────────────────────────────────────────────

    public function test_saldo_incluye_cuenta_madre_id(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('cuentas_bipay')->insert(['alias' => 'Madre', 'tipo' => 'MADRE', 'saldo_actual' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/saldo')
            ->assertOk()
            ->assertJsonStructure(['cuentas' => [['id', 'cuenta_madre_id']]]);
    }

    public function test_admin_vincula_cuenta_huerfana_y_la_convierte_en_madre(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = DB::table('cuentas_bipay')->insertGetId([
            'alias' => 'Huerfana Auto', 'tipo' => 'HIJO', 'cuenta_madre_id' => null, 'saldo_actual' => 100,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bipay/cuentas/{$id}/vincular-huerfana", [
                'razon_social' => 'Comercial Vitaltel SAC',
                'alias' => 'Vitaltel Central',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('cuentas_bipay', [
            'id' => $id,
            'tipo' => 'MADRE',
            'razon_social' => 'Comercial Vitaltel SAC',
            'alias' => 'Vitaltel Central',
        ]);
    }

    public function test_vincular_huerfana_usa_razon_social_como_alias_si_no_se_envia_alias(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Huerfana', 'tipo' => 'HIJO', 'saldo_actual' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bipay/cuentas/{$id}/vincular-huerfana", ['razon_social' => 'Razon X'])
            ->assertOk();

        $this->assertDatabaseHas('cuentas_bipay', ['id' => $id, 'alias' => 'Razon X', 'razon_social' => 'Razon X']);
    }

    public function test_vincular_huerfana_rechaza_no_admin(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $id = DB::table('cuentas_bipay')->insertGetId(['alias' => 'H', 'tipo' => 'HIJO', 'saldo_actual' => 0]);

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/bipay/cuentas/{$id}/vincular-huerfana", ['razon_social' => 'X'])
            ->assertStatus(403);
    }

    public function test_vincular_huerfana_404_si_no_existe(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bipay/cuentas/999/vincular-huerfana', ['razon_social' => 'X'])
            ->assertStatus(404);
    }

    // ── Gap 2: locks activos ─────────────────────────────────────────────────

    public function test_locks_activos_muestra_solo_cooldowns_vigentes_ordenados(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 0]);
        DB::table('tiendas')->insert([
            ['codigo' => 'PUNDA01', 'nombre' => 'Tienda Uno', 'activo' => 1, 'cuenta_bipay_id' => $cuenta],
            ['codigo' => 'PUNDA02', 'nombre' => 'Tienda Dos', 'activo' => 1, 'cuenta_bipay_id' => $cuenta],
        ]);

        DB::table('bipay_cooldowns')->insert([
            ['cuenta_bipay_id' => $cuenta, 'tienda_codigo' => 'PUNDA01', 'cooldown_hasta' => '2026-07-03 10:03:00', 'actualizado_en' => '2026-07-03 09:59:00'],
            ['cuenta_bipay_id' => $cuenta, 'tienda_codigo' => 'PUNDA02', 'cooldown_hasta' => '2026-07-03 09:00:00', 'actualizado_en' => '2026-07-03 08:59:00'], // ya vencido
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/locks-activos')
            ->assertOk()
            ->assertJsonCount(1, 'locks')
            ->assertJsonPath('locks.0.tienda_codigo', 'PUNDA01')
            ->assertJsonPath('locks.0.tienda_nombre', 'Tienda Uno')
            ->assertJsonPath('locks.0.cuenta_alias', 'Cta Uno');
    }

    public function test_locks_activos_rechaza_no_admin(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/bipay/locks-activos')
            ->assertStatus(403);
    }

    // ── Gap 3: export excel de transacciones ─────────────────────────────────

    public function test_exportar_transacciones_genera_xlsx_con_filtros_y_detalle_de_ajuste(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 100]);

        DB::table('transacciones_bipay')->insert([
            [
                'cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY',
                'monto' => 50, 'saldo_origen_pre' => null, 'saldo_anypay_pre' => null,
                'observacion' => 'Recarga inicial', 'creado_por' => $admin->id,
                'creado_en' => '2026-07-03 09:00:00',
            ],
            [
                'cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'AJUSTE', 'plataforma' => 'AMBOS',
                'monto' => 20, 'saldo_origen_pre' => 100, 'saldo_anypay_pre' => 0,
                'observacion' => 'Conteo fisico', 'creado_por' => $admin->id,
                'creado_en' => '2026-07-03 09:30:00',
            ],
            [
                'cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'TRANSFERENCIA', 'plataforma' => 'AMBOS',
                'monto' => 30, 'saldo_origen_pre' => null, 'saldo_anypay_pre' => null,
                'observacion' => null, 'creado_por' => $admin->id,
                'creado_en' => '2026-06-01 09:00:00', // fuera de rango
            ],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/bipay/transacciones/exportar?' . http_build_query([
                'fecha_desde' => '2026-07-01',
                'fecha_hasta' => '2026-07-03',
            ]))
            ->assertOk();

        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('Content-Type')
        );

        $tmp = tempnam(sys_get_temp_dir(), 'bipay') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertSame('Fecha', $sheet->getCell('A1')->getValue());
        // orden DESC por fecha: AJUSTE (09:30) antes que RECARGA (09:00).
        $this->assertSame('AJUSTE', $sheet->getCell('B2')->getValue());
        $this->assertSame('RECARGA', $sheet->getCell('B3')->getValue());
        $this->assertStringContainsString('Ant: S/ 100.00', (string) $sheet->getCell('F2')->getValue());
        $this->assertStringContainsString('S/ 120.00', (string) $sheet->getCell('F2')->getValue());
        // La fila de junio (fuera de rango) no debe aparecer -> solo 2 filas de datos + cabecera.
        $this->assertSame('', (string) $sheet->getCell('A4')->getValue());
    }

    public function test_exportar_transacciones_filtra_por_tipo_operacion(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 0]);

        DB::table('transacciones_bipay')->insert([
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 10, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:00:00'],
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'TRANSFERENCIA', 'plataforma' => 'AMBOS', 'monto' => 20, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:05:00'],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/bipay/transacciones/exportar?' . http_build_query([
                'fecha_desde' => '2026-07-01',
                'fecha_hasta' => '2026-07-03',
                'tipo_operacion' => 'RECARGA',
            ]))
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'bipay') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertSame('RECARGA', $sheet->getCell('B2')->getValue());
        $this->assertSame('', (string) $sheet->getCell('A3')->getValue());
    }

    public function test_exportar_transacciones_rechaza_no_admin(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/bipay/transacciones/exportar')
            ->assertStatus(403);
    }

    // ── Regresión: el cap de 90 días es solo para el export, no para la vista paginada ──

    public function test_transacciones_json_no_acota_rango_explicito_mayor_a_90_dias(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 0]);

        DB::table('transacciones_bipay')->insert([
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 10, 'creado_por' => $admin->id, 'creado_en' => '2026-01-01 09:00:00'],
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 20, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:00:00'],
        ]);

        // Rango explícito de más de 180 días: ambas transacciones deben aparecer, sin recorte.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/transacciones?' . http_build_query([
                'fecha_desde' => '2026-01-01',
                'fecha_hasta' => '2026-07-03',
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_exportar_transacciones_acota_rango_explicito_mayor_a_90_dias_y_anota_excel(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 0]);

        DB::table('transacciones_bipay')->insert([
            // fuera del cap de 90 días (queda antes de 2026-04-04 aprox.) -> no debe aparecer.
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 10, 'creado_por' => $admin->id, 'creado_en' => '2026-01-01 09:00:00'],
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 20, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:00:00'],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/bipay/transacciones/exportar?' . http_build_query([
                'fecha_desde' => '2026-01-01',
                'fecha_hasta' => '2026-07-03',
            ]))
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'bipay') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertStringContainsString('acotado a los últimos 90 días', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('RECARGA', $sheet->getCell('B3')->getValue());
        // Solo la fila de julio (dentro de los últimos 90 días) -> nada más después de la fila 3.
        $this->assertSame('', (string) $sheet->getCell('A4')->getValue());
    }

    public function test_transacciones_json_filtra_por_tipo_operacion(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $cuenta = DB::table('cuentas_bipay')->insertGetId(['alias' => 'Cta Uno', 'saldo_actual' => 0]);

        DB::table('transacciones_bipay')->insert([
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'RECARGA', 'plataforma' => 'BIPAY', 'monto' => 10, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:00:00'],
            ['cuenta_origen_id' => $cuenta, 'tipo_operacion' => 'TRANSFERENCIA', 'plataforma' => 'AMBOS', 'monto' => 20, 'creado_por' => $admin->id, 'creado_en' => '2026-07-03 09:05:00'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bipay/transacciones?' . http_build_query([
                'fecha_desde' => '2026-07-01',
                'fecha_hasta' => '2026-07-03',
                'tipo_operacion' => 'TRANSFERENCIA',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo_operacion', 'TRANSFERENCIA')
            ->assertJsonPath('data.0.operador', $admin->nombre);
    }
}
