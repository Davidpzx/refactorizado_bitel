<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseBBusinessParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporte_persiste_salidas_y_ganancias_operativas(): void
    {
        $usuario = $this->vendedorVinculado();

        DB::table('config_comisiones')->insert([
            ['tipo' => 'ganancia_recargas', 'monto' => 5],
            ['tipo' => 'ganancia_bipay', 'monto' => 2],
        ]);
        DB::table('comisiones_rangos')->insert([
            'tipo_servicio' => 'krece',
            'monto_min' => 100,
            'monto_max' => 200,
            'ganancia' => 12,
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($usuario))
            ->assertCreated()
            ->assertJsonCount(2, 'salidas');

        $reporteId = $response->json('id');

        $this->assertDatabaseHas('reporte_salidas', [
            'reporte_id' => $reporteId,
            'tipo' => 'pasaje',
            'monto' => 10,
            'observacion' => 'Movilidad',
        ]);
        $this->assertDatabaseHas('reportes', [
            'id' => $reporteId,
            'total_salidas' => 15,
            'pago_payjoy' => 80,
        ]);
        $this->assertDatabaseHas('reporte_comisiones_operativas', [
            'reporte_id' => $reporteId,
            'tipo_servicio' => 'recargas',
            'monto_base' => 100,
            'ganancia' => 5,
        ]);
        $this->assertDatabaseHas('reporte_comisiones_operativas', [
            'reporte_id' => $reporteId,
            'tipo_servicio' => 'krece',
            'monto_base' => 150,
            'ganancia' => 12,
        ]);
    }

    public function test_recalculo_masivo_actualiza_operativas_con_tarifas_actuales(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('config_comisiones')->insert([
            'tipo' => 'ganancia_recargas',
            'monto' => 10,
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-10',
            'recarga_bipay' => 200,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/comisiones-planes/recalcular', [
                'fecha_desde' => '2026-06-01',
                'fecha_hasta' => '2026-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('operativas_actualizadas', 1);

        $this->assertDatabaseHas('reporte_comisiones_operativas', [
            'reporte_id' => $reporteId,
            'tipo_servicio' => 'recargas',
            'monto_base' => 200,
            'ganancia' => 20,
        ]);
    }

    public function test_edicion_admin_recalcula_asistencia_y_aprueba_extras(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = DB::table('agentes')->insertGetId([
            'dni' => '12345678',
            'nombres' => 'Agente horario',
            'tienda_base' => 'PUNDA50',
            'estado' => 'ACTIVO',
            'hora_ingreso' => '09:00:00',
            'hora_salida' => '18:00:00',
            'hora_ref_inicio' => '12:00:00',
            'hora_ref_fin' => '13:00:00',
            'dia_descanso' => 'DOMINGO',
        ]);
        $asistenciaId = DB::table('asistencias')->insertGetId([
            'agente_id' => $agenteId,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-15',
            'hora_ingreso' => '09:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/asistencias/{$asistenciaId}", [
                'hora_ingreso' => '09:10',
                'inicio_refrigerio' => '12:00',
                'fin_refrigerio' => '13:20',
                'hora_salida' => '17:30',
                'horas_extras_aprobadas' => 1.5,
                'observacion_admin' => 'Corrección validada',
            ])
            ->assertOk()
            ->assertJsonPath('minutos_tardanza', 30)
            ->assertJsonPath('minutos_deuda', 30)
            ->assertJsonPath('minutos_tardanza_refrigerio', 20);

        $this->assertDatabaseHas('asistencias', [
            'id' => $asistenciaId,
            'minutos_tardanza' => 30,
            'minutos_deuda' => 30,
            'horas_extras_aprobadas' => 1.5,
        ]);

        $this->assertDatabaseHas('log_ediciones_asistencia', [
            'asistencia_id' => $asistenciaId,
            'agente_id' => $agenteId,
            'admin_id' => $admin->id,
            'campo_modificado' => 'hora_ingreso',
            'valor_anterior' => '09:00:00',
            'valor_nuevo' => '09:10:00',
        ]);
        $this->assertDatabaseHas('log_ediciones_asistencia', [
            'asistencia_id' => $asistenciaId,
            'campo_modificado' => 'observacion_admin',
            'valor_nuevo' => 'Corrección validada',
        ]);
        $this->assertDatabaseHas('log_ediciones_asistencia', [
            'asistencia_id' => $asistenciaId,
            'campo_modificado' => 'horas_extras_aprobadas',
            'valor_nuevo' => '1.5',
        ]);
        $this->assertDatabaseMissing('log_ediciones_asistencia', [
            'asistencia_id' => $asistenciaId,
            'campo_modificado' => 'minutos_tardanza',
        ]);
    }

    public function test_eliminar_asistencia_registra_auditoria(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = DB::table('agentes')->insertGetId([
            'dni' => '87654321',
            'nombres' => 'Agente a eliminar',
            'tienda_base' => 'PUNDA50',
            'estado' => 'ACTIVO',
            'hora_ingreso' => '09:00:00',
            'hora_salida' => '18:00:00',
        ]);
        $asistenciaId = DB::table('asistencias')->insertGetId([
            'agente_id' => $agenteId,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-15',
            'hora_ingreso' => '09:00:00',
            'hora_salida' => '18:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/asistencias/{$asistenciaId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('asistencias', ['id' => $asistenciaId]);
        $this->assertDatabaseHas('log_ediciones_asistencia', [
            'asistencia_id' => $asistenciaId,
            'agente_id' => $agenteId,
            'admin_id' => $admin->id,
            'campo_modificado' => 'ELIMINACION',
            'valor_nuevo' => 'ELIMINADO',
        ]);
    }

    public function test_confirmar_desembolso_usa_esquema_tipo_monto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('config_comisiones')->insert([
            'tipo' => 'EQUIPO_ESTANDAR',
            'monto' => 8.5,
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-15',
        ]);
        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId,
            'vendedor_id' => 1,
            'tipo_venta' => 'EQUIPO',
            'comision_estado' => 'PENDIENTE',
            'comision_generada' => 0,
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaId,
            'inventario_tienda_id' => 0,
            'producto_nombre_snap' => 'Equipo financiado',
            'tipo_pago' => 'CUOTAS',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/financieras/{$ventaId}/confirmar-desembolso")
            ->assertOk();

        $this->assertDatabaseHas('ventas', [
            'id' => $ventaId,
            'comision_estado' => 'APROBADA',
            'comision_generada' => 8.5,
        ]);
    }

    public function test_planilla_aplica_rangos_mensuales_de_plan_pero_equipo_siempre_es_plano(): void
    {
        // Paridad legacy: PASO A (PLANES) usa el escalonado mensual (config_comisiones tipo=PLAN),
        // pero PASO B (EQUIPOS) de gerencia/planilla_agentes.php y ver_agente.php:217-219 NUNCA
        // lee la tabla de rangos EQUIPO — siempre paga plano (comision_especial>0, o si no,
        // EQUIPO_ESTANDAR / fallback 5.00). La fila 'EQUIPO' de config_comisiones se deja sembrada
        // a propósito para probar que el refactor la IGNORA, tal como el legacy.
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert([
            'id' => 77,
            'dni' => '00000077',
            'nombres' => 'Agente rangos',
            'tienda_base' => 'PUNDA50',
            'estado' => 'ACTIVO',
            'sueldo_base' => 1200,
        ]);
        DB::table('config_comisiones')->insert([
            ['tipo' => 'PLAN', 'rango_desde' => 1, 'rango_hasta' => 1, 'monto' => 10],
            ['tipo' => 'PLAN', 'rango_desde' => 2, 'rango_hasta' => 9999, 'monto' => 20],
            ['tipo' => 'EQUIPO', 'rango_desde' => 1, 'rango_hasta' => 9999, 'monto' => 7],
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 77,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-10',
        ]);

        foreach (['Plan 1', 'Plan 2'] as $nombrePlan) {
            $ventaId = DB::table('ventas')->insertGetId([
                'reporte_id' => $reporteId,
                'vendedor_id' => 77,
                'tipo_venta' => 'POSTPAGO',
                'comision_generada' => 99,
                'comision_estado' => 'ACTIVA',
                'es_remate' => false,
            ]);
            DB::table('venta_lineas')->insert([
                'venta_id' => $ventaId,
                'plan_nombre_snap' => $nombrePlan,
                'tipo_alta' => 'MNP',
                'cantidad' => 1,
            ]);
        }
        DB::table('ventas')->insert([
            'reporte_id' => $reporteId,
            'vendedor_id' => 77,
            'tipo_venta' => 'EQUIPO',
            'comision_generada' => 0,
            'comision_estado' => 'ACTIVA',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/planilla/2026-06')
            ->assertOk();
        $fila = collect($response->json('agentes'))->firstWhere('agente_id', 77);

        $this->assertSame(30.0, (float) $fila['comision_planes']);
        // Fallback plano 5.00 (no hay fila EQUIPO_ESTANDAR en config_comisiones), NO el
        // rango EQUIPO=7 sembrado arriba: la planilla ignora ese rango, igual que el legacy.
        $this->assertSame(5.0, (float) $fila['comision_equipo']);
    }

    public function test_planilla_paga_equipo_cuotas_pendiente_igual_que_uno_activo_pero_sin_escalonado(): void
    {
        // BUG-027B-01 (QA 027B) confirmado contra el legacy: reportes/procesar_reporte.php:113-126
        // congela comision_agente=0.00 tanto para el equipo ESTÁNDAR normal como para el CUOTAS
        // PENDIENTE (ambos casos "se calcula despues"). gerencia/planilla_agentes.php PASO B EQUIPOS
        // (:348-350) y ver_agente.php:217-219 no filtran por comision_estado ni por tipo_pago: pagan
        // el MISMO fallback plano (EQUIPO_ESTANDAR, default 5.00) para ambos. Pagar el CUOTAS
        // pendiente en planilla es, por tanto, PARIDAD del legacy — no una regresión. Lo que sí era
        // un bug real (ya corregido en PlanillaController::calcularComisionesEquipo) es que el
        // refactor aplicaba el escalonado por rango (montoPorRango) a los equipos con comision=0,
        // lo que podía pagar MÁS que el EQUIPO_ESTANDAR plano que se libera al confirmar el
        // desembolso — asimetría que el legacy nunca tiene, porque nunca usa rango para EQUIPO.
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert([
            'id' => 78,
            'dni' => '00000078',
            'nombres' => 'Agente cuotas',
            'tienda_base' => 'PUNDA50',
            'estado' => 'ACTIVO',
            'sueldo_base' => 1200,
        ]);
        DB::table('config_comisiones')->insert([
            'tipo' => 'EQUIPO_ESTANDAR', 'monto' => 6.0,
        ]);
        DB::table('config_comisiones')->insert([
            'tipo' => 'EQUIPO', 'rango_desde' => 1, 'rango_hasta' => 9999, 'monto' => 50,
        ]);
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 78,
            'tienda_id' => 'PUNDA50',
            'fecha' => '2026-06-11',
        ]);

        // Equipo a CUOTAS aún sin desembolso confirmado: comision_generada=0, comision_estado=PENDIENTE.
        DB::table('ventas')->insert([
            'reporte_id' => $reporteId,
            'vendedor_id' => 78,
            'tipo_venta' => 'EQUIPO',
            'comision_generada' => 0,
            'comision_estado' => 'PENDIENTE',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/planilla/2026-06')
            ->assertOk();
        $fila = collect($response->json('agentes'))->firstWhere('agente_id', 78);

        // Paga el plano EQUIPO_ESTANDAR (6.00), no el rango sembrado (50) ni cero.
        $this->assertSame(6.0, (float) $fila['comision_equipo']);
    }

    private function payload(Usuario $usuario): array
    {
        return [
            'agente_id' => $usuario->agente_id,
            'tienda_id' => 'PUNDA50',
            'fecha' => now()->toDateString(),
            'caja_inicial' => 0,
            'yape' => 0,
            'bipay' => 50,
            'transferencia' => 0,
            'recarga_bipay' => 100,
            'pago_servicio' => 0,
            'pago_krece' => 150,
            'pago_payjoy' => 80,
            'tickets_tusamy' => 0,
            'retiro_bipay' => 0,
            'efectivo_entregado' => 315,
            'total_salidas' => 999,
            'salidas' => [
                ['tipo' => 'pasaje', 'monto' => 10, 'observacion' => 'Movilidad'],
                ['tipo' => 'otro', 'monto' => 5, 'observacion' => 'Caja chica'],
            ],
            'ventas' => [],
        ];
    }
}
