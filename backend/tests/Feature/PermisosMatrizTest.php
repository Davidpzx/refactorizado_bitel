<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\Permisos;
use Tests\TestCase;

/**
 * Plan 16 — R1: Permisos::puede() según la matriz congelada. Muestra representativa
 * de celdas, con foco en las anticorrupción (jefe de tienda no modifica asistencias /
 * no aprueba traslados / no fija precios; gerente no crea administradores ni toca
 * config técnica). No usa BD: usuarios en memoria.
 */
class PermisosMatrizTest extends TestCase
{
    private function usuario(string $rol): Usuario
    {
        $u = new Usuario();
        $u->rol = $rol;

        return $u;
    }

    public function test_administrador_supera_cualquier_permiso_por_jerarquia(): void
    {
        $admin = $this->usuario('administrador');

        $this->assertTrue(Permisos::puede($admin, 'crear_administradores'));
        $this->assertTrue(Permisos::puede($admin, 'config_tecnica'));
        $this->assertTrue(Permisos::puede($admin, 'modificar_asistencias'));
        // Alias legacy 'admin' se normaliza a administrador.
        $this->assertTrue(Permisos::puede($this->usuario('admin'), 'aprobar_traslados'));
    }

    public function test_gerente_gestiona_negocio_pero_no_es_administrador(): void
    {
        $gerente = $this->usuario('gerente');

        $this->assertTrue(Permisos::puede($gerente, 'gestionar_usuarios'));
        $this->assertTrue(Permisos::puede($gerente, 'modificar_asistencias'));
        // Anticorrupción / exclusivos del admin:
        $this->assertFalse(Permisos::puede($gerente, 'crear_administradores'));
        $this->assertFalse(Permisos::puede($gerente, 'config_tecnica'));
    }

    public function test_jefe_tienda_no_puede_las_acciones_anticorrupcion(): void
    {
        $jefe = $this->usuario('jefe_tienda');

        $this->assertFalse(Permisos::puede($jefe, 'modificar_asistencias'));
        $this->assertFalse(Permisos::puede($jefe, 'aprobar_traslados'));
        $this->assertFalse(Permisos::puede($jefe, 'fijar_precios'));
        $this->assertFalse(Permisos::puede($jefe, 'gestionar_usuarios'));
        // Pero sí ve costos (flag default ON en su tienda).
        $this->assertTrue(Permisos::puede($jefe, 'ver_costos'));
        // Alias legacy 'tienda'/'vendedor' se comportan igual que jefe_tienda.
        $this->assertFalse(Permisos::puede($this->usuario('tienda'), 'aprobar_traslados'));
        $this->assertTrue(Permisos::puede($this->usuario('vendedor'), 'ver_costos'));
    }

    public function test_agente_no_ve_costos_ni_gestiona(): void
    {
        $agente = $this->usuario('agente');

        $this->assertFalse(Permisos::puede($agente, 'ver_costos'));
        $this->assertFalse(Permisos::puede($agente, 'gestionar_usuarios'));
        $this->assertFalse(Permisos::puede($agente, 'aprobar_traslados'));
    }

    public function test_usuario_nulo_o_permiso_desconocido_no_pasa(): void
    {
        $this->assertFalse(Permisos::puede(null, 'ver_costos'));
        $this->assertFalse(Permisos::puede($this->usuario('gerente'), 'permiso_inexistente'));
    }
}
