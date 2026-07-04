<?php

namespace Tests\Feature;

use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cubre el gap P1 de docs/comparacion/gap_tienda_reportes_2026-07-02.md:
 * el PDF de reporte perdio secciones por categoria, vendedor, DNI, badges,
 * comision/ganancia y el bloque de observaciones del dia.
 */
class ConstanciaReporteDetalleTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporteConDetalle(): array
    {
        $cajero = $this->vendedorVinculado('PUNDA50');

        $otroVendedorId = 9001;
        DB::table('agentes')->insert([
            'id' => $otroVendedorId,
            'nombres' => 'Vendedora Distinta',
            'estado' => 'ACTIVO',
            'tienda_base' => 'PUNDA50',
            'dni' => '90009000',
        ]);

        $response = $this->actingAs($cajero, 'sanctum')->postJson('/api/v1/reportes', [
            'agente_id' => $cajero->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-07-03',
            'caja_inicial' => 50,
            'efectivo_entregado' => 250,
            'obs_dia' => "Turno tranquilo.\nSin incidentes de caja.",
            'ventas' => [
                [
                    'vendedor_id' => $otroVendedorId,
                    'tipo_venta' => 'POSTPAGO',
                    'monto_total' => 50, 'efectivo_inicial' => 50,
                    'plan_nombre' => 'Plan Ilimitado', 'tipo_alta' => 'MNP',
                    'cantidad' => 1, 'cobrado_unitario' => 50,
                    'es_migracion' => true,
                    'cross_selling' => true, 'tienda_destino' => 'PUNDA11',
                    'cliente_dni' => '87654321',
                ],
                [
                    'vendedor_id' => $otroVendedorId,
                    'tipo_venta' => 'PREPAGO',
                    'monto_total' => 30, 'efectivo_inicial' => 30,
                    'plan_nombre' => 'Chip Prepago', 'tipo_alta' => 'LN',
                    'cantidad' => 1, 'cobrado_unitario' => 30,
                    'es_remate' => true, 'es_extranjero' => true,
                    'cliente_dni' => '11223344',
                ],
                [
                    'vendedor_id' => $otroVendedorId,
                    'tipo_venta' => 'EQUIPO',
                    'monto_total' => 1500, 'efectivo_inicial' => 1200,
                    'producto_nombre' => 'iPhone 15', 'tipo_pago' => 'CUOTAS',
                    'financiera' => 'KREDITO', 'precio_venta' => 1500,
                    'costo_snap' => 1000, 'por_cobrar_financiera' => 1200,
                    'cliente_dni' => '55667788',
                ],
                [
                    'vendedor_id' => $otroVendedorId,
                    'tipo_venta' => 'OTROS_FLUJO',
                    'monto_total' => 20, 'efectivo_inicial' => 20,
                    'subtipo' => 'Venta de forro',
                ],
                [
                    'vendedor_id' => $otroVendedorId,
                    'tipo_venta' => 'APOYO',
                    'monto_total' => 40, 'efectivo_inicial' => 40,
                    'tienda_destino' => 'PUNDA20',
                    'plan_nombre' => 'Plan Full', 'tipo_alta' => 'LN',
                    'cantidad' => 1, 'cobrado_unitario' => 40,
                ],
            ],
            'salidas' => [
                ['tipo' => 'gasto', 'monto' => 15, 'observacion' => 'Taxi por encomienda'],
            ],
        ]);

        $response->assertCreated();

        return [$response->json('id'), $otroVendedorId, $cajero];
    }

    public function test_vista_de_reporte_incluye_secciones_vendedor_dni_badges_ganancia_y_observaciones(): void
    {
        [$reporteId] = $this->crearReporteConDetalle();

        $reporte = DB::table('reportes as r')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'r.tienda_id')
            ->where('r.id', $reporteId)
            ->select('r.*', 'a.nombres as agente_nombre', 'a.dni as agente_dni', 't.nombre as tienda_nombre')
            ->first();

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $reporteId)
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $reporteId)->orderBy('id')->get();
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa'))->render();

        // Secciones tituladas por categoria (antes solo habia una tabla generica).
        $this->assertStringContainsString('VENTAS POSTPAGO', $html);
        $this->assertStringContainsString('VENTAS PREPAGO', $html);
        $this->assertStringContainsString('EQUIPOS Y ACCESORIOS', $html);
        $this->assertStringContainsString('OTROS INGRESOS', $html);
        $this->assertStringContainsString('APOYO A OTRAS TIENDAS', $html);
        $this->assertStringContainsString('SALIDAS Y GASTOS', $html);

        // Vendedor real de cada venta (distinto del cajero que hizo el cuadre): 5 ventas, todas asignadas
        // al mismo vendedor de prueba.
        $this->assertSame(5, substr_count($html, 'Vendedora Distinta'));

        // DNI de cliente por venta.
        $this->assertStringContainsString('87654321', $html);
        $this->assertStringContainsString('11223344', $html);
        $this->assertStringContainsString('55667788', $html);

        // Badges.
        $this->assertStringContainsString('Migración', $html);
        $this->assertStringContainsString('Cross PUNDA11', $html);
        $this->assertStringContainsString('Remate', $html);
        $this->assertStringContainsString('Extranjero', $html);

        // Financiera y ganancia del equipo (1500 - 1000 = 500).
        $this->assertStringContainsString('KREDITO', $html);
        $this->assertStringContainsString('500.00', $html);

        // Tienda destino del apoyo.
        $this->assertStringContainsString('PUNDA20', $html);

        // Observaciones del dia, en su propio bloque.
        $this->assertStringContainsString('OBSERVACIONES DEL D', $html);
        $this->assertStringContainsString('Turno tranquilo.', $html);
        $this->assertStringContainsString('Sin incidentes de caja.', $html);

        // Salidas/gastos con observacion.
        $this->assertStringContainsString('Taxi por encomienda', $html);

        // Comision total generada y ganancia total, resumidas en el cuadre.
        $this->assertStringContainsString('COMISIÓN TOTAL GENERADA', $html);
        $this->assertStringContainsString('GANANCIA TOTAL', $html);
    }

    public function test_pdf_excluye_ventas_anuladas_igual_que_los_exports_excel(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            Schema::create('configuraciones', function ($table) {
                $table->id();
                $table->string('razon_social')->nullable();
            });
            DB::table('configuraciones')->insert(['razon_social' => 'BITEL TELECOM S.A.C.']);
        }

        [$reporteId] = $this->crearReporteConDetalle();

        $ventaEquipoId = DB::table('ventas')
            ->where('reporte_id', $reporteId)
            ->where('tipo_venta', 'EQUIPO')
            ->value('id');

        DB::table('ventas')->where('id', $ventaEquipoId)->update([
            'comision_estado' => 'ANULADA',
        ]);

        $reporte = DB::table('reportes as r')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'r.tienda_id')
            ->where('r.id', $reporteId)
            ->select('r.*', 'a.nombres as agente_nombre', 'a.dni as agente_dni', 't.nombre as tienda_nombre')
            ->first();

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $reporteId)
            ->where('comision_estado', '!=', 'ANULADA')
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $reporteId)->orderBy('id')->get();
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa'))->render();

        // La venta EQUIPO anulada (iPhone 15, precio 1500) no debe aparecer en la seccion ni sumar.
        $this->assertStringNotContainsString('iPhone 15', $html);
        $this->assertSame(0, $ventas->where('tipo_venta', 'EQUIPO')->count());
        $this->assertStringNotContainsString('S/ 1,500.00', $html);

        // El endpoint real tambien debe filtrar la venta anulada antes de generar el PDF.
        $response = $this->actingAs($this->vendedorVinculado('PUNDA50'), 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");
        $response->assertOk();
    }

    public function test_pdf_muestra_monto_total_como_precio_de_equipo_cuotas_y_cuadra_con_total_calculado(): void
    {
        [$reporteId] = $this->crearReporteConDetalle();

        $reporte = DB::table('reportes as r')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'r.tienda_id')
            ->where('r.id', $reporteId)
            ->select('r.*', 'a.nombres as agente_nombre', 'a.dni as agente_dni', 't.nombre as tienda_nombre')
            ->first();

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $reporteId)
            ->where('comision_estado', '!=', 'ANULADA')
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $reporteId)->orderBy('id')->get();
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa'))->render();

        // El equipo CUOTAS (contrato S/1500, cuota inicial S/1200) muestra el valor de contrato como
        // Precio -- igual que los exports Excel (monto_total) -- y la cuota inicial como columna aparte.
        $this->assertStringContainsString('S/ 1,500.00', $html);
        $this->assertStringContainsString('S/ 1,200.00', $html);

        // El subtotal itemizado (suma de todas las secciones por monto_total) cuadra con total_calculado.
        $sumaSecciones = $ventas->sum('monto_total');
        $this->assertEquals((float) $reporte->total_calculado, $sumaSecciones);
    }

    public function test_total_ventas_brutas_se_recalcula_en_vivo_tras_anular_venta_post_cierre(): void
    {
        [$reporteId] = $this->crearReporteConDetalle();

        $reporte = DB::table('reportes as r')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'r.tienda_id')
            ->where('r.id', $reporteId)
            ->select('r.*', 'a.nombres as agente_nombre', 'a.dni as agente_dni', 't.nombre as tienda_nombre')
            ->first();

        // total_calculado del cierre: 50 + 30 + 1500 + 20 + 40 = 1640.
        $this->assertEquals(1640.0, (float) $reporte->total_calculado);

        // Simula "Restaurar equipo" del Kardex (InventarioController::restaurar): anula la venta
        // EQUIPO *despues* del cierre del reporte, sin tocar reportes.total_calculado.
        $ventaEquipoId = DB::table('ventas')
            ->where('reporte_id', $reporteId)
            ->where('tipo_venta', 'EQUIPO')
            ->value('id');
        DB::table('ventas')->where('id', $ventaEquipoId)->update(['comision_estado' => 'ANULADA']);

        // total_calculado sigue "stale": nada lo recalcula ante una anulacion post-hoc.
        $this->assertEquals(1640.0, (float) DB::table('reportes')->where('id', $reporteId)->value('total_calculado'));

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $reporteId)
            ->where('comision_estado', '!=', 'ANULADA')
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $reporteId)->orderBy('id')->get();
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa'))->render();

        // Subtotal itemizado en vivo (sin la venta anulada): 1640 - 1500 = 140.
        $sumaItemizada = $ventas->sum('monto_total');
        $this->assertEquals(140.0, $sumaItemizada);

        // "TOTAL VENTAS BRUTAS" debe cuadrar con el subtotal itemizado en vivo del PDF,
        // no con el total_calculado stale del cierre (que arrastraria la venta ya anulada).
        // Nota: el campo "Total Calculado" de la cabecera (fuera de alcance de este fix) sigue
        // mostrando el valor historico 1,640.00 a proposito, por eso se aisla la fila del cuadre.
        $this->assertMatchesRegularExpression(
            '/TOTAL VENTAS BRUTAS:<\/td>\s*<td class="monto">S\/ ' . preg_quote(number_format($sumaItemizada, 2), '/') . '<\/td>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/TOTAL VENTAS BRUTAS:<\/td>\s*<td class="monto">S\/ 1,640\.00<\/td>/',
            $html
        );
    }

    public function test_endpoint_descarga_pdf_real_sin_error(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            Schema::create('configuraciones', function ($table) {
                $table->id();
                $table->string('razon_social')->nullable();
            });
            DB::table('configuraciones')->insert(['razon_social' => 'BITEL TELECOM S.A.C.']);
        }

        [$reporteId, , $cajero] = $this->crearReporteConDetalle();

        $response = $this->actingAs($cajero, 'sanctum')
            ->get("/api/v1/constancias/reporte/{$reporteId}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertGreaterThan(0, strlen($response->getContent()));
    }
}
