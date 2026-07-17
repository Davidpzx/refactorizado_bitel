<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ingesta M2M del agente on-premise (POST /v1/integrador/recibir-saldo).
 *
 * Antes las escrituras usaban SQL crudo MySQL (ON DUPLICATE KEY UPDATE / INSERT IGNORE)
 * que no corre en sqlite → 0 cobertura. Portadas a upsert()/insertOrIgnore() portables,
 * estas pruebas verifican: persistencia, idempotencia del upsert, escritura de
 * last_sync_at y que insertOrIgnore no rompa ante duplicados.
 *
 * Auth: la del grupo v1/integrador (API key global + timestamp), sin sesión de usuario.
 */
class IntegradorRecibirSaldoTest extends TestCase
{
    use RefreshDatabase;

    private const TIENDA = 'PUNDA50';

    private const API_KEY = 'test-integrador-key';

    private int $cuentaId;

    protected function setUp(): void
    {
        parent::setUp();

        // El default hardcodeado del config se eliminó (SEC-01); en testing no hay env var,
        // así que fijamos una key conocida en runtime para poder ejercitar el happy path.
        config(['services.integrador.api_key' => self::API_KEY]);

        // cuentas_bipay / transacciones_bipay no están migradas en este repo (viven en el
        // legacy); se crean ad-hoc igual que BipayAdminTest.
        Schema::create('cuentas_bipay', function (Blueprint $t) {
            $t->id();
            $t->string('alias', 100)->nullable();
            $t->string('tipo', 20)->nullable();
            $t->decimal('saldo_bipay', 12, 2)->default(0);
            $t->decimal('saldo_anypay', 12, 2)->default(0);
            $t->decimal('saldo_actual', 12, 2)->default(0);
            $t->decimal('umbral_alerta', 12, 2)->default(0);
            $t->string('estado_api', 50)->nullable();
        });
        Schema::create('transacciones_bipay', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cuenta_origen_id')->nullable();
            $t->string('tipo_operacion', 30);
            $t->string('plataforma', 20)->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->decimal('saldo_origen_pre', 12, 2)->nullable();
            $t->decimal('saldo_anypay_pre', 12, 2)->nullable();
            $t->string('observacion', 255)->nullable();
            $t->unsignedBigInteger('creado_por')->nullable();
        });

        $this->cuentaId = DB::table('cuentas_bipay')->insertGetId([
            'alias' => 'Tienda '.self::TIENDA, 'tipo' => 'HIJO',
            'saldo_bipay' => 0, 'saldo_anypay' => 0, 'saldo_actual' => 0, 'umbral_alerta' => 0,
        ]);

        DB::table('tiendas')->insert([
            'codigo' => self::TIENDA, 'nombre' => 'Tienda Test',
            'cuenta_bipay_id' => (string) $this->cuentaId, 'radio_permitido' => 60,
        ]);

        DB::table('integrador_credenciales')->insert([
            'tienda_codigo' => self::TIENDA,
            'bitel_username' => 'USUARIO_TEST',
            'bitel_password_enc' => 'x',
            'sync_intervalo_min' => 15,
            'agente_token_hash' => hash('sha256', 'token-de-prueba'),
            'activo' => 1,
            'last_sync_at' => null,
        ]);
    }

    /** Payload M2M válido con un movimiento postpago de $monto en $fecha. */
    private function payload(float $saldoBipay = 500, float $monto = 100, string $fecha = '2026-07-01'): array
    {
        return [
            'api_key' => config('services.integrador.api_key'),
            'timestamp' => time(),
            'saldo_bipay' => $saldoBipay,
            'channel_code' => self::TIENDA,
            'cod_tienda' => self::TIENDA,
            'fecha_reporte' => $fecha,
            'movimientos' => [[
                'cod_tienda' => self::TIENDA,
                'categoria' => 'postpago',
                'cantidad' => $monto,
                'tiempo' => $fecha.' 10:00:00',
                'tipo_transaccion' => 'POSTPAGO',
                'descripcion' => 'Venta postpago',
                'isdn_cliente' => '999888777',
            ]],
        ];
    }

    public function test_recibir_saldo_persiste_movimientos_y_saldo(): void
    {
        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload())
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(500.0, (float) DB::table('cuentas_bipay')->where('id', $this->cuentaId)->value('saldo_actual'));
        $this->assertDatabaseHas('bitel_movimientos_diarios', [
            'tienda_codigo' => self::TIENDA, 'fecha' => '2026-07-01', 'postpago' => 100, 'total_neto' => 100,
        ]);
        $this->assertDatabaseHas('bitel_operaciones_detalle', [
            'tienda_codigo' => self::TIENDA, 'tipo_operacion' => 'POSTPAGO',
        ]);
    }

    public function test_upsert_es_idempotente_no_duplica_el_dia(): void
    {
        // Primer sync: postpago 100.
        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload(monto: 100))->assertOk();
        // Segundo sync del MISMO día con otro valor: debe ACTUALIZAR, no crear otra fila.
        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload(monto: 250))->assertOk();

        $this->assertSame(1, DB::table('bitel_movimientos_diarios')
            ->where('tienda_codigo', self::TIENDA)->where('fecha', '2026-07-01')->count());
        $this->assertSame(250.0, (float) DB::table('bitel_movimientos_diarios')
            ->where('tienda_codigo', self::TIENDA)->where('fecha', '2026-07-01')->value('postpago'));
    }

    public function test_last_sync_at_se_actualiza_tras_un_sync_exitoso(): void
    {
        $this->assertNull(DB::table('integrador_credenciales')->where('tienda_codigo', self::TIENDA)->value('last_sync_at'));

        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload())->assertOk();

        $this->assertNotNull(DB::table('integrador_credenciales')->where('tienda_codigo', self::TIENDA)->value('last_sync_at'));
    }

    public function test_insert_or_ignore_no_rompe_ante_detalle_duplicado(): void
    {
        // Dos movimientos idénticos en el mismo payload → misma clave única
        // (tienda_codigo, fecha_hora, descripcion, monto): el segundo se ignora sin error.
        $payload = $this->payload();
        $payload['movimientos'][] = $payload['movimientos'][0];

        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, DB::table('bitel_operaciones_detalle')
            ->where('tienda_codigo', self::TIENDA)->where('descripcion', 'like', 'Venta postpago%')->count());

        // Reenviar exactamente el mismo sync se detecta como replay y no duplica.
        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertConflict()->assertJsonPath('ok', false);
        $this->assertSame(1, DB::table('bitel_operaciones_detalle')
            ->where('tienda_codigo', self::TIENDA)->where('descripcion', 'like', 'Venta postpago%')->count());
    }

    public function test_recibir_morosidad_upsert_clientes_y_lineas_es_idempotente(): void
    {
        $base = [
            'api_key' => config('services.integrador.api_key'),
            'timestamp' => time(),
            'periodo' => '2026-07',
            'clientes_estado' => [[
                'numero_linea' => '999888777', 'dni_cliente' => '12345678',
                'cod_tienda' => self::TIENDA, 'estado_linea' => 'SUSPENDIDO',
                'dias_mora' => 15, 'monto_deuda' => 30.5, 'periodo' => '2026-07',
            ]],
            'lineas_morosidad' => [[
                'numero_linea' => '999888777', 'cliente' => 'Juan Perez',
                'cod_tienda' => self::TIENDA, 'monto_deuda' => 30.5, 'periodo' => '2026-07',
            ]],
        ];

        $this->postJson('/api/v1/integrador/recibir-morosidad', $base)->assertOk()->assertJsonPath('ok', true);
        // Segundo envío del mismo periodo/línea con dato cambiado: actualiza, no duplica.
        $base['timestamp'] = time();
        $base['clientes_estado'][0]['monto_deuda'] = 99.9;
        $this->postJson('/api/v1/integrador/recibir-morosidad', $base)->assertOk();

        $this->assertSame(1, DB::table('clientes_estado')
            ->where('numero_linea', '999888777')->where('periodo', '2026-07')->count());
        $this->assertSame(99.9, (float) DB::table('clientes_estado')
            ->where('numero_linea', '999888777')->where('periodo', '2026-07')->value('monto_deuda'));
        $this->assertSame(1, DB::table('lineas_morosidad')
            ->where('numero_linea', '999888777')->where('periodo', '2026-07')->count());
    }

    public function test_sin_api_key_es_rechazado(): void
    {
        $payload = $this->payload();
        unset($payload['api_key']);

        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertStatus(403);
    }

    public function test_api_key_correcta_sigue_funcionando(): void
    {
        // Regresión SEC-01: tras quitar el default hardcodeado, la key configurada válida
        // debe seguir autenticando exactamente igual que antes.
        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload())
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_api_key_vacia_es_rechazada(): void
    {
        $payload = $this->payload();
        $payload['api_key'] = '';

        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertStatus(403);
    }

    public function test_api_key_incorrecta_es_rechazada(): void
    {
        $payload = $this->payload();
        $payload['api_key'] = 'clave-que-no-corresponde';

        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertStatus(403);
    }

    public function test_key_central_vacia_rechaza_aunque_request_tampoco_mande_key(): void
    {
        // Con el config vacío/null, ni siquiera un request sin api_key debe colar
        // (evita que '' === '' o null === null pase como válido).
        config(['services.integrador.api_key' => null]);
        $payload = $this->payload();
        unset($payload['api_key']);

        $this->postJson('/api/v1/integrador/recibir-saldo', $payload)->assertStatus(403);

        config(['services.integrador.api_key' => '']);
        $this->postJson('/api/v1/integrador/recibir-saldo', $this->payload())->assertStatus(403);
    }

    public function test_config_no_tiene_api_key_hardcodeada_por_defecto(): void
    {
        // Sin la env var INTEGRADOR_API_KEY, el config NO debe caer en un secreto
        // hardcodeado: el default se eliminó, debe resolver a null.
        $prev = [
            'env' => $_ENV['INTEGRADOR_API_KEY'] ?? null,
            'server' => $_SERVER['INTEGRADOR_API_KEY'] ?? null,
            'getenv' => getenv('INTEGRADOR_API_KEY'),
        ];
        unset($_ENV['INTEGRADOR_API_KEY'], $_SERVER['INTEGRADOR_API_KEY']);
        putenv('INTEGRADOR_API_KEY');

        try {
            // Se re-evalúa el archivo de config con la env var ausente (bypassa el cache
            // de config del contenedor, que setUp sobrescribió con una key de prueba).
            $config = require base_path('config/services.php');

            $this->assertNull($config['integrador']['api_key']);
            $this->assertNotSame(
                'KyrO+-tomowrroland-skrillex-2026?-wazak-vegetta777',
                $config['integrador']['api_key']
            );
        } finally {
            if ($prev['env'] !== null) {
                $_ENV['INTEGRADOR_API_KEY'] = $prev['env'];
            }
            if ($prev['server'] !== null) {
                $_SERVER['INTEGRADOR_API_KEY'] = $prev['server'];
            }
            if ($prev['getenv'] !== false) {
                putenv('INTEGRADOR_API_KEY='.$prev['getenv']);
            }
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('transacciones_bipay');
        Schema::dropIfExists('cuentas_bipay');
        parent::tearDown();
    }
}
