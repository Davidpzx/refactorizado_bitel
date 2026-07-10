<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cubre A3-01 y A3-02 de plan/05-qa-funcional-A.md:
 *
 *  - `ConstanciaController` leia `DB::table('configuraciones')`, tabla que ninguna
 *    migracion crea; la real es `configuracion_empresa`. Los cuatro endpoints de
 *    constancia daban 500 en cualquier BD migrada de cero.
 *  - El `COLLATE utf8mb4_unicode_ci` hardcodeado de los JOIN con `tiendas` hacia
 *    imposible ejercer `/constancias/traslado` en SQLite, el motor de la suite,
 *    asi que el endpoint no tenia ni una sola prueba.
 *
 * Ningun test de este archivo fabrica esquema: todo sale de las migraciones.
 */
class ConstanciaTrasladoTest extends TestCase
{
    use RefreshDatabase;

    private const AGENTE_ENVIA    = 5001;
    private const AGENTE_CONFIRMA = 5002;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('configuracion_empresa')->insert([
            'razon_social' => 'BITEL TELECOM S.A.C.',
            'ruc'          => '20512345678',
        ]);

        DB::table('tiendas')->insert([
            ['codigo' => 'PUNDA50', 'nombre' => 'Tienda Origen'],
            ['codigo' => 'PUNDA11', 'nombre' => 'Tienda Destino'],
        ]);

        DB::table('agentes')->insert([
            [
                'id' => self::AGENTE_ENVIA, 'nombres' => 'Agente Envia', 'estado' => 'ACTIVO',
                'tienda_base' => 'PUNDA50', 'dni' => '50015001',
            ],
            [
                'id' => self::AGENTE_CONFIRMA, 'nombres' => 'Agente Confirma', 'estado' => 'ACTIVO',
                'tienda_base' => 'PUNDA11', 'dni' => '50025002',
            ],
        ]);
    }

    /** Devuelve el id de un traslado de equipos CONFIRMADO de PUNDA50 a PUNDA11. */
    private function crearTrasladoEquipos(?string $lote = null): int
    {
        $productoId = DB::table('inventario_tiendas')->insertGetId([
            'tienda_id'       => 'PUNDA11',
            'producto_nombre' => 'Cargador Turbo 33W',
            'tipo'            => 'ACCESORIO',
            'imei_serial'     => 'SN-ACC-0001',
            'cantidad'        => 1,
            'estado'          => 'DISPONIBLE',
        ]);

        return DB::table('traslados_stock')->insertGetId([
            'producto_id'           => $productoId,
            'tienda_origen'         => 'PUNDA50',
            'tienda_destino'        => 'PUNDA11',
            'cantidad'              => 1,
            'estado'                => 'CONFIRMADO',
            'creado_por'            => 1,
            'notas'                 => 'Reposicion de accesorios',
            'codigo_lote'           => $lote,
            'enviado_por_id'        => self::AGENTE_ENVIA,
            'enviado_dni'           => '50015001',
            'confirmado_por_id'     => self::AGENTE_CONFIRMA,
            'confirmado_dni'        => '50025002',
            'observacion_recepcion' => 'Recibido conforme',
            'producto_nombre_snap'  => 'Cargador Turbo 33W',
            'imei_serial_snap'      => 'SN-ACC-0001',
            'fecha_confirmacion'    => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    private function crearTrasladoChips(): int
    {
        $chipId = DB::table('inventario_chips')->insertGetId([
            'tienda_id'     => 1,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip'     => 'FISICO',
            'stock_actual'  => 20,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return DB::table('traslados_chips')->insertGetId([
            'chip_id_origen'     => $chipId,
            'tienda_origen'      => 'PUNDA50',
            'tienda_destino'     => 'PUNDA11',
            'cantidad'           => 10,
            'estado'             => 'CONFIRMADO',
            'creado_por'         => 1,
            'enviado_por_id'     => self::AGENTE_ENVIA,
            'enviado_dni'        => '50015001',
            'confirmado_por_id'  => self::AGENTE_CONFIRMA,
            'confirmado_dni'     => '50025002',
            'fecha_confirmacion' => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function assertEsPdf($response): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ── /constancias/traslado ─────────────────────────────────────────────────

    public function test_traslado_de_equipos_por_id_devuelve_pdf(): void
    {
        $trasladoId = $this->crearTrasladoEquipos();
        $usuario    = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->assertEsPdf(
            $this->actingAs($usuario, 'sanctum')
                ->get("/api/v1/constancias/traslado?tipo=equipos&id={$trasladoId}")
        );
    }

    public function test_traslado_de_equipos_por_lote_devuelve_pdf(): void
    {
        $this->crearTrasladoEquipos('LOTE-QA-027');
        $this->crearTrasladoEquipos('LOTE-QA-027');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->assertEsPdf(
            $this->actingAs($usuario, 'sanctum')
                ->get('/api/v1/constancias/traslado?tipo=equipos&lote=LOTE-QA-027')
        );
    }

    public function test_traslado_de_chips_devuelve_pdf(): void
    {
        $trasladoId = $this->crearTrasladoChips();
        $admin      = Usuario::factory()->admin()->create();

        $this->assertEsPdf(
            $this->actingAs($admin, 'sanctum')
                ->get("/api/v1/constancias/traslado?tipo=chips&id={$trasladoId}")
        );
    }

    public function test_el_join_con_tiendas_resuelve_los_nombres_de_origen_y_destino(): void
    {
        $trasladoId = $this->crearTrasladoEquipos();
        $usuario    = Usuario::factory()->vendedor('PUNDA50')->create();

        // El COLLATE hardcodeado tumbaba esta consulta en SQLite antes del fix;
        // si el JOIN se hubiese eliminado en vez de arreglarse, el PDF saldria
        // sin los nombres de tienda. Se replica el JOIN del controller.
        $fila = DB::table('traslados_stock as ts')
            ->leftJoin('tiendas as te', 'te.codigo', '=', 'ts.tienda_origen')
            ->leftJoin('tiendas as td', 'td.codigo', '=', 'ts.tienda_destino')
            ->where('ts.id', $trasladoId)
            ->select('te.nombre as origen', 'td.nombre as destino')
            ->first();

        $this->assertSame('Tienda Origen', $fila->origen);
        $this->assertSame('Tienda Destino', $fila->destino);

        $this->assertEsPdf(
            $this->actingAs($usuario, 'sanctum')
                ->get("/api/v1/constancias/traslado?tipo=equipos&id={$trasladoId}")
        );
    }

    public function test_usuario_de_una_tienda_ajena_al_traslado_recibe_403(): void
    {
        $trasladoId = $this->crearTrasladoEquipos();
        $ajeno      = Usuario::factory()->vendedor('PUNDA20')->create();

        $this->actingAs($ajeno, 'sanctum')
            ->get("/api/v1/constancias/traslado?tipo=equipos&id={$trasladoId}")
            ->assertForbidden();
    }

    public function test_usuario_sin_tienda_id_falla_cerrado_con_403(): void
    {
        $trasladoId = $this->crearTrasladoEquipos();
        $sinTienda  = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $this->actingAs($sinTienda, 'sanctum')
            ->get("/api/v1/constancias/traslado?tipo=equipos&id={$trasladoId}")
            ->assertForbidden();
    }

    public function test_traslado_inexistente_devuelve_404(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/constancias/traslado?tipo=equipos&id=99999')
            ->assertNotFound();
    }

    public function test_sin_id_ni_lote_devuelve_422(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/constancias/traslado')
            ->assertStatus(422);
    }

    // ── Los 4 endpoints, contra la tabla real ────────────────────────────────

    /**
     * Prueba de no-regresion de A3-01: `configuraciones` no existe en el esquema
     * y ningun test la fabrica, asi que cualquier vuelta a ese nombre de tabla
     * hace explotar los cuatro endpoints con "no such table".
     */
    public function test_los_cuatro_endpoints_generan_pdf_contra_configuracion_empresa(): void
    {
        $this->assertFalse(
            Schema::hasTable('configuraciones'),
            'La tabla `configuraciones` no debe existir: el esquema real es `configuracion_empresa`.'
        );
        $this->assertSame('BITEL TELECOM S.A.C.', DB::table('configuracion_empresa')->value('razon_social'));

        $admin = Usuario::factory()->admin()->create();

        // 1) traslado
        $trasladoId = $this->crearTrasladoEquipos();
        $this->assertEsPdf(
            $this->actingAs($admin, 'sanctum')->get("/api/v1/constancias/traslado?tipo=equipos&id={$trasladoId}")
        );

        // 2) agente
        $this->assertEsPdf(
            $this->actingAs($admin, 'sanctum')->get('/api/v1/constancias/agente/'.self::AGENTE_ENVIA)
        );

        // 3) boleta
        $boletaId = DB::table('pagos_planilla')->insertGetId([
            'agente_id'           => self::AGENTE_ENVIA,
            'fecha_inicio'        => '2026-06-01',
            'fecha_fin'           => '2026-06-15',
            'sueldo_base'         => 1200,
            'bonos_comisiones'    => 0,
            'descuento_tardanza'  => 0,
            'descuento_adelantos' => 0,
            'total_pagado'        => 1200,
            'estado'              => 'PENDIENTE',
            'fecha_pago'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        $this->assertEsPdf(
            $this->actingAs($admin, 'sanctum')->get("/api/v1/constancias/boleta/{$boletaId}")
        );

        // 4) reporte
        $cajero   = $this->vendedorVinculado('PUNDA50');
        $reporteId = $this->actingAs($cajero, 'sanctum')->postJson('/api/v1/reportes', [
            'agente_id'          => $cajero->agente_id,
            'tienda_id'          => 'PUNDA50',
            'fecha'              => '2026-07-03',
            'caja_inicial'       => 50,
            'efectivo_entregado' => 50,
        ])->assertCreated()->json('id');

        $this->assertEsPdf(
            $this->actingAs($admin, 'sanctum')->get("/api/v1/constancias/reporte/{$reporteId}")
        );
    }
}
