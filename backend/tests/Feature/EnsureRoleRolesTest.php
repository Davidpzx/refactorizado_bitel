<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Plan 16 — R1: EnsureRole con el modelo de 4 roles.
 * - Acepta alias legacy (admin≡administrador, tienda/vendedor≡jefe_tienda) en ambos lados.
 * - Acepta los roles nuevos.
 * - Jerarquía: el administrador pasa cualquier check.
 * No usa BD: construye usuarios en memoria y ejecuta el middleware directamente.
 */
class EnsureRoleRolesTest extends TestCase
{
    private function usuario(string $rol): Usuario
    {
        $u = new Usuario();
        $u->rol = $rol;

        return $u;
    }

    /** @return int status: 200 si pasa, 403 si el middleware aborta. */
    private function ejecutar(Usuario $usuario, string ...$rolesRequeridos): int
    {
        $mw = new EnsureRole();
        $request = Request::create('/x', 'GET');
        $request->setUserResolver(fn () => $usuario);

        try {
            $resp = $mw->handle($request, fn () => new Response('ok', 200), ...$rolesRequeridos);

            return $resp->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_alias_admin_pasa_ruta_declarada_como_administrador(): void
    {
        $this->assertSame(200, $this->ejecutar($this->usuario('admin'), 'administrador'));
    }

    public function test_rol_nuevo_administrador_pasa_ruta_declarada_como_admin(): void
    {
        $this->assertSame(200, $this->ejecutar($this->usuario('administrador'), 'admin'));
    }

    public function test_tienda_y_vendedor_pasan_admin_tienda(): void
    {
        $this->assertSame(200, $this->ejecutar($this->usuario('tienda'), 'admin', 'tienda'));
        $this->assertSame(200, $this->ejecutar($this->usuario('vendedor'), 'admin', 'tienda'));
    }

    public function test_jefe_tienda_nuevo_pasa_admin_tienda(): void
    {
        $this->assertSame(200, $this->ejecutar($this->usuario('jefe_tienda'), 'admin', 'tienda'));
    }

    public function test_agente_recibe_403_en_admin_tienda(): void
    {
        $this->assertSame(403, $this->ejecutar($this->usuario('agente'), 'admin', 'tienda'));
    }

    public function test_jerarquia_administrador_pasa_ruta_solo_gerente(): void
    {
        // El administrador no está listado, pero la jerarquía lo deja pasar.
        $this->assertSame(200, $this->ejecutar($this->usuario('administrador'), 'gerente'));
        // El alias legacy también.
        $this->assertSame(200, $this->ejecutar($this->usuario('admin'), 'gerente'));
    }

    public function test_gerente_recibe_403_en_ruta_solo_administrador(): void
    {
        $this->assertSame(403, $this->ejecutar($this->usuario('gerente'), 'administrador'));
    }

    public function test_jefe_tienda_recibe_403_en_ruta_solo_admin(): void
    {
        // Preserva el comportamiento legacy: 'tienda' no accede a rutas de solo admin.
        $this->assertSame(403, $this->ejecutar($this->usuario('tienda'), 'admin'));
    }
}
