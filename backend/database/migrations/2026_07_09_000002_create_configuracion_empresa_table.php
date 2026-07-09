<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de Empresa (`gerencia/configuracion_empresa.php` legacy): razón
 * social, RUC, datos de contacto y logo.
 *
 * El logo se guarda como data URI base64 en `logo_base64` (no como archivo en
 * disco: el filesystem de producción es de solo lectura fuera de volúmenes
 * montados). Fila única `id = 1`, igual que el legacy (`WHERE id = 1`).
 *
 * `ConfiguracionController` ya defiende con `Schema::hasTable()` el caso en
 * que esta tabla venga adoptada del legacy con otro nombre de columnas; si la
 * tabla ya existe (BD adoptada) esta migración no la toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configuracion_empresa')) {
            return;
        }

        Schema::create('configuracion_empresa', function (Blueprint $table) {
            $table->increments('id');
            $table->string('razon_social', 200)->default('');
            $table->string('nombre_comercial', 200)->nullable();
            $table->string('ruc', 11)->default('');
            $table->string('gerente_general', 100)->nullable();
            $table->string('gerente_dni', 8)->nullable();
            $table->string('sistema_nombre', 100)->default('SIS-KYRO');
            $table->string('telefono_contacto', 20)->nullable();
            $table->string('direccion_principal', 255)->nullable();
            $table->string('correo_contacto', 100)->nullable();
            $table->longText('logo_base64')->nullable();
        });
    }

    public function down(): void
    {
        // Tabla potencialmente compartida con el legacy: no se elimina en rollback.
    }
};
