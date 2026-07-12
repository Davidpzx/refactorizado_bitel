<?php

namespace App\Support;

use App\Models\Usuario;

/**
 * Fundación del modelo de permisos (plan 16). Los roles son *paquetes de permisos*:
 * el check real es por permiso; el rol sólo los agrupa. Esto permite flags como
 * `ver_costos` sin inventar roles nuevos.
 *
 * IMPORTANTE (R1): esto es SÓLO la fundación. Aquí NO se aplican los permisos a rutas
 * ni a controladores — eso es R2-R4. Este helper existe para que esas capas consulten
 * {@see Permisos::puede()} de forma centralizada.
 *
 * Matriz congelada — plan/16-plan-roles.md. Principio anticorrupción: lo que modifica
 * el registro de asistencia, aprueba traslados, fija precios o toca planilla es SOLO
 * admin/gerente; el jefe de tienda convive a diario con los agentes y no debe poder
 * "ayudarlos".
 */
final class Permisos
{
    /**
     * permiso → roles canónicos (además de administrador) que lo tienen.
     * El administrador se resuelve por jerarquía en {@see puede()} y no se lista aquí.
     *
     * @var array<string, array<int, string>>
     */
    private const MATRIZ = [
        // Ver costos en inventario/kardex/matriz. Jefe de tienda: flag `ver_costos`
        // default ON (plan 16, fila Inventario). Agente: nunca.
        'ver_costos'            => [Usuario::ROL_GERENTE, Usuario::ROL_JEFE_TIENDA],

        // Anticorrupción: sólo admin/gerente.
        'aprobar_traslados'     => [Usuario::ROL_GERENTE],
        'modificar_asistencias' => [Usuario::ROL_GERENTE],
        'fijar_precios'         => [Usuario::ROL_GERENTE],
        'gestionar_planilla'    => [Usuario::ROL_GERENTE],

        // Operación del negocio: admin/gerente.
        'ver_dashboard_global'  => [Usuario::ROL_GERENTE],
        'gestionar_usuarios'    => [Usuario::ROL_GERENTE],
        'gestionar_financieras' => [Usuario::ROL_GERENTE],
        'gestionar_tiendas'     => [Usuario::ROL_GERENTE],
        'perfil_empresa'        => [Usuario::ROL_GERENTE],
        'postulaciones_rrhh'    => [Usuario::ROL_GERENTE],

        // Exclusivos del administrador (el gerente NO los tiene).
        'crear_administradores' => [],
        'config_tecnica'        => [], // Config Facturación/SUNAT, Integrador Bitel, Diagnóstico
    ];

    /**
     * ¿El usuario tiene el permiso indicado?
     *
     * El administrador supera cualquier permiso por jerarquía (incluidos permisos aún
     * no mapeados). Los alias legacy de rol se resuelven vía Usuario::rolCanonico().
     */
    public static function puede(?Usuario $usuario, string $permiso): bool
    {
        if (! $usuario) {
            return false;
        }

        if ($usuario->esAdministrador()) {
            return true;
        }

        $roles = self::MATRIZ[$permiso] ?? [];

        return in_array($usuario->rolCanonico(), $roles, true);
    }

    /**
     * Lista de permisos conocidos (para introspección/UI). No incluye la jerarquía
     * del administrador, que es implícita.
     *
     * @return array<int, string>
     */
    public static function permisos(): array
    {
        return array_keys(self::MATRIZ);
    }
}
