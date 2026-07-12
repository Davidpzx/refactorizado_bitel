<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 16 — Fundación del modelo de 4 roles.
 *
 * Convierte los valores legacy de `usuarios.rol` a los canónicos del nuevo modelo:
 *   'admin'  → 'administrador'
 *   'tienda' → 'jefe_tienda'
 *
 * Es retrocompatible: EnsureRole y Usuario::normalizarRol() siguen aceptando los
 * alias viejos ('admin', 'tienda', 'vendedor') durante la transición, así que
 * sesiones/tokens vivos y las factories de tests no se rompen.
 *
 * La columna en este refactor es VARCHAR (ver create_usuarios_table), pero si en
 * algún entorno (p.ej. una BD heredada del legacy) fuese ENUM en MySQL, primero se
 * amplía la lista de valores permitidos. SQLite no tiene ENUM (usa TEXT), así que ese
 * paso se omite en el driver de tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usuarios')) {
            return;
        }

        $this->ampliarEnumSiAplica(['administrador', 'gerente', 'jefe_tienda', 'agente']);

        DB::table('usuarios')->where('rol', 'admin')->update(['rol' => 'administrador']);
        DB::table('usuarios')->where('rol', 'tienda')->update(['rol' => 'jefe_tienda']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('usuarios')) {
            return;
        }

        DB::table('usuarios')->where('rol', 'administrador')->update(['rol' => 'admin']);
        DB::table('usuarios')->where('rol', 'jefe_tienda')->update(['rol' => 'tienda']);
    }

    /**
     * Si la columna `rol` es un ENUM de MySQL, la reconstruye añadiendo los valores
     * nuevos (preservando los existentes, la nulabilidad y el default). No hace nada
     * en drivers sin ENUM (SQLite) ni cuando la columna ya es VARCHAR/TEXT.
     *
     * @param  array<int, string>  $nuevos
     */
    private function ampliarEnumSiAplica(array $nuevos): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::selectOne(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS `default`
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['usuarios', 'rol']
        );

        if (! $col || stripos((string) $col->type, 'enum') !== 0) {
            return;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string) $col->type, $m);
        $valores = $m[1] ?? [];

        foreach ($nuevos as $nuevo) {
            if (! in_array($nuevo, $valores, true)) {
                $valores[] = $nuevo;
            }
        }

        $lista = implode(',', array_map(static fn ($v) => "'" . addslashes($v) . "'", $valores));
        $null  = strtoupper((string) $col->nullable) === 'YES' ? 'NULL' : 'NOT NULL';
        $def   = $col->default === null
            ? ($null === 'NULL' ? 'DEFAULT NULL' : '')
            : "DEFAULT '" . addslashes((string) $col->default) . "'";

        DB::statement(trim("ALTER TABLE usuarios MODIFY COLUMN rol ENUM($lista) $null $def"));
    }
};
