<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/control-center')->assertUnauthorized();
    }

    public function test_rechaza_usuarios_no_administradores(): void
    {
        $usuario = Usuario::factory()->vendedor()->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/control-center')
            ->assertForbidden()
            ->assertJson(['message' => 'Solo administradores.']);
    }

    public function test_devuelve_los_ocho_indicadores_con_datos_legacy(): void
    {
        Carbon::setTestNow('2026-06-11 10:30:00');
        $admin = Usuario::factory()->admin()->create();

        $this->crearTablasOpcionales();
        $this->insertarDatosBase();
        $this->insertarDatosOpcionales();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/control-center')
            ->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'anomalias_caja' => ['count', 'data'],
                'precios_pendientes' => ['count'],
                'traslados_pendientes' => ['count', 'equipos_count', 'chips_count', 'data'],
                'postulantes_pendientes' => ['count'],
                'financieras_pendientes' => ['count'],
                'alertas_bcp' => ['count', 'data'],
                'alertas_bipay' => ['count', 'data'],
                'notificaciones_sistema' => ['count', 'data'],
            ]);

        $response
            ->assertJsonPath('anomalias_caja.count', 1)
            ->assertJsonPath('anomalias_caja.data.0.agente_id', 10)
            ->assertJsonPath('anomalias_caja.data.0.dias_desc', 3)
            ->assertJsonPath('anomalias_caja.data.0.mayor_diferencia', 12)
            ->assertJsonPath('precios_pendientes.count', 1)
            ->assertJsonPath('traslados_pendientes.count', 3)
            ->assertJsonPath('traslados_pendientes.equipos_count', 2)
            ->assertJsonPath('traslados_pendientes.chips_count', 1)
            ->assertJsonPath('traslados_pendientes.data.0.tipo_lote', 'chips')
            ->assertJsonPath('traslados_pendientes.data.1.codigo_lote', 'LOTE-1')
            ->assertJsonPath('traslados_pendientes.data.1.cantidad', 2)
            ->assertJsonPath('postulantes_pendientes.count', 1)
            ->assertJsonPath('financieras_pendientes.count', 1)
            ->assertJsonPath('alertas_bcp.count', 1)
            ->assertJsonPath('alertas_bcp.data.0.total_operaciones', 150)
            ->assertJsonPath('alertas_bipay.count', 1)
            ->assertJsonPath('alertas_bipay.data.0.cuenta_alias', 'Caja Central')
            ->assertJsonPath('notificaciones_sistema.count', 2)
            ->assertJsonPath('notificaciones_sistema.data.0.mensaje', 'Alerta mas reciente');
    }

    public function test_devuelve_ceros_si_las_tablas_opcionales_no_existen(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/control-center')
            ->assertOk()
            ->assertJsonPath('financieras_pendientes.count', 0)
            ->assertJsonPath('alertas_bcp.count', 0)
            ->assertJsonPath('alertas_bipay.count', 0)
            ->assertJsonPath('notificaciones_sistema.count', 0);
    }

    public function test_marcar_notificacion_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/marcar-notificacion', [
            'id' => 1,
        ])->assertUnauthorized();
    }

    public function test_marcar_notificacion_rechaza_usuarios_no_administradores(): void
    {
        $usuario = Usuario::factory()->vendedor()->create();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/marcar-notificacion', ['id' => 1])
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'mensaje' => 'No autorizado',
            ]);
    }

    public function test_marcar_notificacion_rechaza_id_invalido(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/marcar-notificacion')
            ->assertBadRequest()
            ->assertJson([
                'success' => false,
                'mensaje' => 'ID invalido',
            ]);
    }

    public function test_marca_notificacion_como_leida_por_defecto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearTablasOpcionales();

        $id = DB::table('sys_notificaciones')->insertGetId([
            'tipo' => 'alerta_asistencia',
            'mensaje' => 'Notificacion pendiente',
            'fecha_creacion' => '2026-06-11 10:00:00',
            'estado' => 'pendiente',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/marcar-notificacion', ['id' => $id])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseHas('sys_notificaciones', [
            'id' => $id,
            'estado' => 'leido',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/control-center')
            ->assertOk()
            ->assertJsonPath('notificaciones_sistema.count', 0);
    }

    public function test_accion_desconocida_marca_notificacion_como_leida(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearTablasOpcionales();

        $id = DB::table('sys_notificaciones')->insertGetId([
            'tipo' => 'alerta_asistencia',
            'mensaje' => 'Notificacion pendiente',
            'fecha_creacion' => '2026-06-11 10:00:00',
            'estado' => 'pendiente',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/marcar-notificacion', [
                'id' => $id,
                'accion' => 'desconocida',
            ])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseHas('sys_notificaciones', [
            'id' => $id,
            'estado' => 'leido',
        ]);
    }

    public function test_borra_notificacion_fisicamente(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearTablasOpcionales();

        $id = DB::table('sys_notificaciones')->insertGetId([
            'tipo' => 'alerta_asistencia',
            'mensaje' => 'Notificacion para borrar',
            'fecha_creacion' => '2026-06-11 10:00:00',
            'estado' => 'pendiente',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/marcar-notificacion', [
                'id' => $id,
                'accion' => 'borrar',
            ])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('sys_notificaciones', ['id' => $id]);
    }

    private function crearTablasOpcionales(): void
    {
        Schema::create('reporte_categorias', function (Blueprint $table) {
            $table->id();
            $table->string('comision_estado', 20);
        });

        Schema::create('reportes_bcp', function (Blueprint $table) {
            $table->id();
            $table->boolean('alerta_vista')->default(false);
            $table->date('fecha');
            $table->unsignedInteger('sucursal_id');
            $table->integer('cantidad_operaciones');
        });

        Schema::create('cuentas_bipay', function (Blueprint $table) {
            $table->id();
            $table->string('alias');
            $table->decimal('umbral_alerta', 10, 2);
        });

        Schema::create('bipay_saldos_dia', function (Blueprint $table) {
            $table->id();
            $table->string('tienda_codigo', 20);
            $table->unsignedBigInteger('cuenta_bipay_id');
            $table->date('fecha');
            $table->decimal('saldo_actual', 10, 2)->nullable();
            $table->decimal('saldo_cierre', 10, 2)->nullable();
            $table->boolean('alerta_enviada')->default(false);
        });

        Schema::create('sys_notificaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50);
            $table->text('mensaje');
            $table->dateTime('fecha_creacion');
            $table->string('estado', 20);
        });
    }

    private function insertarDatosBase(): void
    {
        DB::table('tiendas')->insert([
            'codigo' => 'PUNDA01',
            'nombre' => 'Tienda Uno',
            'activo' => 1,
        ]);

        DB::table('agentes')->insert([
            ['id' => 10, 'nombres' => 'Cajero Alerta', 'estado' => 'ACTIVO'],
            ['id' => 11, 'nombres' => 'Cajero Normal', 'estado' => 'ACTIVO'],
        ]);

        foreach ([
            ['agente_id' => 10, 'fecha' => '2026-06-11', 'diferencia' => 12],
            ['agente_id' => 10, 'fecha' => '2026-06-10', 'diferencia' => -9],
            ['agente_id' => 10, 'fecha' => '2026-06-09', 'diferencia' => 7],
            ['agente_id' => 11, 'fecha' => '2026-06-11', 'diferencia' => 20],
            ['agente_id' => 11, 'fecha' => '2026-06-10', 'diferencia' => 20],
        ] as $reporte) {
            DB::table('reportes')->insert(array_merge([
                'tienda_id' => 'PUNDA01',
                'total_dia' => 0,
                'total_calculado' => 0,
                'estado' => 'cerrado',
            ], $reporte));
        }

        DB::table('inventario_tiendas')->insert([
            [
                'id' => 1,
                'tienda_id' => 'PUNDA01',
                'producto_nombre' => 'Equipo sin precio',
                'tipo' => 'EQUIPO',
                'precio_costo' => 0,
                'precio_minimo' => 100,
                'precio_normal' => 120,
                'estado' => 'DISPONIBLE',
            ],
            [
                'id' => 2,
                'tienda_id' => 'PUNDA01',
                'producto_nombre' => 'Equipo completo',
                'tipo' => 'EQUIPO',
                'precio_costo' => 80,
                'precio_minimo' => 100,
                'precio_normal' => 120,
                'estado' => 'DISPONIBLE',
            ],
            [
                'id' => 3,
                'tienda_id' => 'PUNDA01',
                'producto_nombre' => 'Chip excluido',
                'tipo' => 'CHIP',
                'precio_costo' => 0,
                'precio_minimo' => 0,
                'precio_normal' => 0,
                'estado' => 'DISPONIBLE',
            ],
        ]);

        DB::table('traslados_stock')->insert([
            [
                'producto_id' => 1,
                'tienda_origen' => 'PUNDA01',
                'tienda_destino' => 'PUNDA02',
                'cantidad' => 1,
                'estado' => 'PENDIENTE_APROBACION',
                'creado_por' => 1,
                'codigo_lote' => 'LOTE-1',
                'created_at' => '2026-06-11 09:00:00',
                'updated_at' => '2026-06-11 09:00:00',
            ],
            [
                'producto_id' => 2,
                'tienda_origen' => 'PUNDA01',
                'tienda_destino' => 'PUNDA02',
                'cantidad' => 1,
                'estado' => 'PENDIENTE_APROBACION',
                'creado_por' => 1,
                'codigo_lote' => 'LOTE-1',
                'created_at' => '2026-06-11 09:00:00',
                'updated_at' => '2026-06-11 09:00:00',
            ],
        ]);

        DB::table('traslados_chips')->insert([
            'chip_id_origen' => 1,
            'tienda_origen' => 'PUNDA01',
            'tienda_destino' => 'PUNDA02',
            'cantidad' => 50,
            'estado' => 'PENDIENTE_APROBACION',
            'creado_por' => 1,
            'created_at' => '2026-06-11 10:00:00',
            'updated_at' => '2026-06-11 10:00:00',
        ]);

        DB::table('postulantes_temp')->insert([
            [
                'dni' => '12345678',
                'nombres' => 'Pendiente',
                'apellidos' => 'Uno',
                'estado' => 'PENDIENTE',
            ],
            [
                'dni' => '87654321',
                'nombres' => 'Aprobado',
                'apellidos' => 'Dos',
                'estado' => 'APROBADO',
            ],
        ]);
    }

    private function insertarDatosOpcionales(): void
    {
        // F1: el badge de financieras ahora cuenta ventas.comision_estado (esquema normalizado).
        DB::table('ventas')->insert([
            ['reporte_id' => 1, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO', 'monto_total' => 100,
             'efectivo_inicial' => 100, 'comision_generada' => 0, 'comision_estado' => 'PENDIENTE', 'creado_en' => now()],
            ['reporte_id' => 1, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO', 'monto_total' => 100,
             'efectivo_inicial' => 100, 'comision_generada' => 5, 'comision_estado' => 'APROBADA', 'creado_en' => now()],
        ]);

        DB::table('reportes_bcp')->insert([
            ['alerta_vista' => 0, 'fecha' => '2026-06-11', 'sucursal_id' => 1, 'cantidad_operaciones' => 100],
            ['alerta_vista' => 0, 'fecha' => '2026-06-11', 'sucursal_id' => 1, 'cantidad_operaciones' => 50],
            ['alerta_vista' => 0, 'fecha' => '2026-06-11', 'sucursal_id' => 2, 'cantidad_operaciones' => 210],
            ['alerta_vista' => 1, 'fecha' => '2026-06-11', 'sucursal_id' => 3, 'cantidad_operaciones' => 10],
        ]);

        DB::table('cuentas_bipay')->insert([
            ['id' => 1, 'alias' => 'Caja Central', 'umbral_alerta' => 100],
            ['id' => 2, 'alias' => 'Caja Secundaria', 'umbral_alerta' => 50],
        ]);

        DB::table('bipay_saldos_dia')->insert([
            [
                'tienda_codigo' => 'PUNDA01',
                'cuenta_bipay_id' => 1,
                'fecha' => '2026-06-11',
                'saldo_actual' => 80,
                'saldo_cierre' => null,
                'alerta_enviada' => 1,
            ],
            [
                'tienda_codigo' => 'PUNDA02',
                'cuenta_bipay_id' => 2,
                'fecha' => '2026-06-11',
                'saldo_actual' => 40,
                'saldo_cierre' => null,
                'alerta_enviada' => 0,
            ],
        ]);

        DB::table('sys_notificaciones')->insert([
            [
                'tipo' => 'alerta_asistencia',
                'mensaje' => 'Alerta anterior',
                'fecha_creacion' => '2026-06-11 09:00:00',
                'estado' => 'pendiente',
            ],
            [
                'tipo' => 'alerta_seguridad',
                'mensaje' => 'Alerta mas reciente',
                'fecha_creacion' => '2026-06-11 10:00:00',
                'estado' => 'pendiente',
            ],
            [
                'tipo' => 'alerta_asistencia',
                'mensaje' => 'Alerta leida',
                'fecha_creacion' => '2026-06-11 08:00:00',
                'estado' => 'leido',
            ],
        ]);
    }
}
