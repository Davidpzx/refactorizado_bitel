<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 16 — R1: la data migration convierte los roles legacy a los canónicos y su
 * down() los revierte. Se ejecuta la migración a mano sobre filas sembradas (la
 * tabla ya existe por RefreshDatabase, así que up() sólo actualiza datos).
 */
class RolesMigracionTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_07_12_000001_convertir_roles_a_modelo_4.php');
    }

    private function sembrar(string $email, string $rol): void
    {
        DB::table('usuarios')->insert([
            'nombre'   => "U {$rol}",
            'email'    => $email,
            'password' => bcrypt('x'),
            'rol'      => $rol,
            'activo'   => true,
        ]);
    }

    public function test_up_convierte_admin_y_tienda_a_los_canonicos(): void
    {
        $this->sembrar('a@x.test', 'admin');
        $this->sembrar('t@x.test', 'tienda');
        $this->sembrar('v@x.test', 'vendedor');
        $this->sembrar('g@x.test', 'agente');

        $this->migracion()->up();

        $this->assertSame('administrador', DB::table('usuarios')->where('email', 'a@x.test')->value('rol'));
        $this->assertSame('jefe_tienda', DB::table('usuarios')->where('email', 't@x.test')->value('rol'));
        // vendedor y agente no son parte de esta conversión y quedan intactos.
        $this->assertSame('vendedor', DB::table('usuarios')->where('email', 'v@x.test')->value('rol'));
        $this->assertSame('agente', DB::table('usuarios')->where('email', 'g@x.test')->value('rol'));
    }

    public function test_down_revierte_a_los_valores_legacy(): void
    {
        $this->sembrar('a@x.test', 'admin');
        $this->sembrar('t@x.test', 'tienda');

        $migracion = $this->migracion();
        $migracion->up();
        $migracion->down();

        $this->assertSame('admin', DB::table('usuarios')->where('email', 'a@x.test')->value('rol'));
        $this->assertSame('tienda', DB::table('usuarios')->where('email', 't@x.test')->value('rol'));
    }
}
