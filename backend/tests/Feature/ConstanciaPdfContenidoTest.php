<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ticket-042: las constancias PDF (agente, boleta) solo tenían tests de
 * autenticación (assertOk + content-type). Igual que ConstanciaReporteDetalleTest,
 * renderizamos la vista Blade directamente (dompdf produce binario no
 * indexable) para verificar que los datos reales del agente/boleta aparecen.
 */
class ConstanciaPdfContenidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vista_certificado_agente_incluye_datos_reales(): void
    {
        $agente = (object) [
            'nombres' => 'Maria Fernanda Quispe',
            'dni' => '44556677',
            'tienda_base' => 'PUNDA50',
            'tienda_nombre' => 'Tienda Punda 50',
            'estado' => 'ACTIVO',
            'fecha_ingreso' => '2025-01-15',
            'telefono' => '999888777',
            'correo' => null,
            'es_gerencia' => true,
        ];
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.agente', compact('agente', 'empresa'))->render();

        $this->assertStringContainsString('Maria Fernanda Quispe', $html);
        $this->assertStringContainsString('44556677', $html);
        $this->assertStringContainsString('PUNDA50', $html);
        $this->assertStringContainsString('Tienda Punda 50', $html);
        $this->assertStringContainsString('ACTIVO', $html);
        $this->assertStringContainsString('Gerencia / Responsable de tienda', $html);
        $this->assertStringContainsString('BITEL TELECOM S.A.C.', $html);
    }

    public function test_vista_boleta_incluye_montos_y_datos_reales(): void
    {
        $pago = (object) [
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-15',
            'fecha_pago' => '2026-06-16 10:00:00',
            'sueldo_base' => 750.00,
            'bonos_comisiones' => 120.50,
            'descuento_tardanza' => 15.00,
            'descuento_adelantos' => 50.00,
            'total_pagado' => 805.50,
        ];
        $agente = (object) [
            'nombres' => 'Carlos Alberto Ruiz',
            'dni' => '99887766',
            'tienda_base' => 'PUNDA50',
        ];
        $empresa = (object) ['razon_social' => 'BITEL TELECOM S.A.C.'];

        $html = view('constancias.boleta', compact('pago', 'agente', 'empresa'))->render();

        $this->assertStringContainsString('Carlos Alberto Ruiz', $html);
        $this->assertStringContainsString('99887766', $html);
        $this->assertStringContainsString('750.00', $html);
        $this->assertStringContainsString('120.50', $html);
        $this->assertStringContainsString('15.00', $html);
        $this->assertStringContainsString('50.00', $html);
        $this->assertStringContainsString('805.50', $html);
    }
}
