<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── tiendas ────────────────────────────────────────────────────────────
        if (!Schema::hasTable('tiendas')) {
            Schema::create('tiendas', function (Blueprint $table) {
                $table->string('codigo', 20)->primary();
                $table->string('nombre', 100);
                $table->string('direccion', 200)->nullable();
                $table->string('telefono', 20)->nullable();
                $table->boolean('activo')->default(true);
                $table->string('cuenta_bipay_id', 50)->nullable();
                $table->integer('radio_permitido')->default(60);
                $table->decimal('lat_centro', 10, 7)->nullable();
                $table->decimal('lng_centro', 10, 7)->nullable();
            });
        }

        // ── reportes ───────────────────────────────────────────────────────────
        if (!Schema::hasTable('reportes')) {
            Schema::create('reportes', function (Blueprint $table) {
                $table->id();
                $table->integer('agente_id');
                $table->string('tienda_id', 50);
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->date('fecha');
                $table->decimal('total_dia', 12, 2)->default(0);
                $table->decimal('total_calculado', 12, 2)->default(0);
                $table->decimal('yape', 12, 2)->default(0);
                $table->decimal('bipay', 12, 2)->default(0);
                $table->decimal('recarga_bipay', 12, 2)->default(0);
                $table->decimal('pago_servicio', 12, 2)->default(0);
                $table->decimal('pago_krece', 12, 2)->default(0);
                $table->decimal('tickets_tusamy', 12, 2)->default(0);
                $table->decimal('retiro_bipay', 12, 2)->default(0);
                $table->decimal('transferencia', 12, 2)->default(0);
                $table->decimal('caja_inicial', 12, 2)->default(0);
                $table->decimal('efectivo_entregado', 12, 2)->default(0);
                $table->decimal('total_salidas', 12, 2)->default(0);
                $table->decimal('total_restantes', 12, 2)->default(0);
                $table->decimal('efectivo_esperado', 12, 2)->default(0);
                $table->decimal('diferencia', 12, 2)->default(0);
                $table->string('estado', 30)->default('borrador');
                $table->boolean('requiere_aprobacion')->default(false);
                $table->unsignedBigInteger('aprobado_por')->nullable();
                $table->timestamp('fecha_aprobacion')->nullable();
                $table->string('nombre_cubre', 100)->nullable();
                $table->text('observaciones')->nullable();
                $table->text('obs_dia')->nullable();
                $table->string('destino_efectivo', 50)->default('TIENDA');
                $table->string('estado_edicion', 30)->nullable();
                $table->text('motivo_edicion')->nullable();
                $table->timestamps();

                $table->index(['agente_id', 'fecha']);
                $table->index('tienda_id');
            });
        }

        // ── ventas ─────────────────────────────────────────────────────────────
        if (!Schema::hasTable('ventas')) {
            Schema::create('ventas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reporte_id');
                $table->integer('vendedor_id');
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->string('tipo_venta', 30);
                $table->string('subtipo', 50)->nullable();
                $table->boolean('cross_selling')->default(false);
                $table->string('tienda_destino', 20)->nullable();
                $table->decimal('monto_total', 12, 2)->default(0);
                $table->decimal('efectivo_inicial', 12, 2)->default(0);
                $table->decimal('comision_generada', 12, 2)->default(0);
                $table->string('comision_estado', 20)->default('ACTIVA');
                $table->boolean('es_remate')->default(false);
                $table->boolean('es_extranjero')->default(false);
                $table->timestamp('creado_en')->useCurrent();

                $table->index('reporte_id');
                $table->index('vendedor_id');
            });
        }

        // ── venta_equipos ──────────────────────────────────────────────────────
        if (!Schema::hasTable('venta_equipos')) {
            Schema::create('venta_equipos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('venta_id');
                $table->unsignedBigInteger('inventario_tienda_id')->default(0);
                $table->string('producto_nombre_snap', 150);
                $table->string('imei_serial_snap', 50)->nullable();
                $table->string('tipo_item', 30)->default('EQUIPO');
                $table->string('tipo_pago', 20)->default('CONTADO');
                $table->string('financiera', 50)->nullable();
                $table->decimal('precio_venta', 12, 2)->default(0);
                $table->decimal('costo_snap', 12, 2)->default(0);
                $table->decimal('ganancia_snap', 12, 2)->nullable();
                $table->decimal('por_cobrar_financiera', 12, 2)->default(0);

                $table->index('venta_id');
            });
        }

        // ── venta_lineas ───────────────────────────────────────────────────────
        if (!Schema::hasTable('venta_lineas')) {
            Schema::create('venta_lineas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('venta_id');
                $table->string('plan_nombre_snap', 150);
                $table->string('tipo_alta', 30)->default('LN');
                $table->integer('cantidad')->default(1);
                $table->decimal('cobrado_unitario', 12, 2)->default(0);
                $table->decimal('comision_unitaria', 12, 2)->default(0);
                $table->boolean('es_esim')->default(false);

                $table->index('venta_id');
            });
        }

        // ── inventario_tiendas ─────────────────────────────────────────────────
        if (!Schema::hasTable('inventario_tiendas')) {
            Schema::create('inventario_tiendas', function (Blueprint $table) {
                $table->id();
                $table->string('tienda_id', 20);
                $table->string('producto_nombre', 150);
                $table->string('tipo', 30)->default('EQUIPO');
                $table->string('imei_serial', 50)->nullable();
                $table->decimal('precio_costo', 12, 2)->default(0);
                $table->decimal('precio_minimo', 12, 2)->default(0);
                $table->decimal('precio_normal', 12, 2)->default(0);
                $table->integer('cantidad')->default(1);
                $table->string('estado', 20)->default('DISPONIBLE');
                $table->string('registrado_por', 100)->nullable();
                $table->date('fecha_registro')->nullable();
                $table->date('fecha_venta')->nullable();
                $table->integer('vendido_por_id')->nullable();
                $table->unsignedBigInteger('reporte_venta_id')->nullable();
                $table->decimal('comision_especial', 12, 2)->nullable();

                $table->index('tienda_id');
                $table->index('estado');
            });
        }

        // ── comprobantes ───────────────────────────────────────────────────────
        if (!Schema::hasTable('comprobantes')) {
            Schema::create('comprobantes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('venta_id');
                $table->string('tipo_comprobante', 20)->default('BOLETA');
                $table->string('serie', 10)->nullable();
                $table->integer('numero')->nullable();
                $table->string('estado_sunat', 20)->default('PENDIENTE');
                $table->string('xml_path', 255)->nullable();
                $table->string('cdr_path', 255)->nullable();
                $table->string('hash_cpe', 64)->nullable();
                $table->text('mensaje_sunat')->nullable();
                $table->integer('intentos')->default(0);
                $table->timestamp('proximo_intento')->nullable();
                $table->timestamp('creado_en')->useCurrent();

                $table->index('venta_id');
                $table->index('estado_sunat');
            });
        }

        // ── comisiones_planes ──────────────────────────────────────────────────
        if (!Schema::hasTable('comisiones_planes')) {
            Schema::create('comisiones_planes', function (Blueprint $table) {
                $table->id();
                $table->string('tipo_servicio', 30);
                $table->string('nombre_plan', 150);
                $table->string('tipo_alta', 30)->default('LN');
                $table->decimal('fee_monto', 10, 2)->default(0);
                $table->decimal('comision_dni_n', 10, 2)->default(0);
                $table->decimal('comision_dni_n3', 10, 2)->default(0);
                $table->decimal('comision_ext_n', 10, 2)->default(0);
                $table->decimal('comision_ext_n3', 10, 2)->default(0);
            });
        }

        // ── asistencias ────────────────────────────────────────────────────────
        if (!Schema::hasTable('asistencias')) {
            Schema::create('asistencias', function (Blueprint $table) {
                $table->id();
                $table->integer('agente_id');
                $table->string('tienda_id', 20)->nullable();
                $table->date('fecha');
                $table->time('hora_ingreso')->nullable();
                $table->time('hora_salida')->nullable();
                $table->time('inicio_refrigerio')->nullable();
                $table->time('fin_refrigerio')->nullable();
                $table->string('tienda_ingreso', 20)->nullable();
                $table->integer('minutos_tardanza')->default(0);
                $table->integer('minutos_deuda')->default(0);
                $table->integer('minutos_extra')->default(0);
                $table->boolean('omitio_refrigerio')->default(false);
                $table->boolean('turno_extendido')->default(false);
                $table->string('estado_asistencia', 20)->default('REGULAR');
                $table->string('foto_marcacion', 500)->nullable();
                $table->boolean('requiere_revision')->default(false);
                $table->string('metodo_marcacion', 10)->default('MANUAL');
                $table->decimal('lat_entrada', 10, 7)->nullable();
                $table->decimal('lng_entrada', 10, 7)->nullable();
                $table->decimal('accuracy_entrada', 8, 2)->nullable();
                $table->decimal('distancia_entrada', 10, 2)->nullable();
                $table->dateTime('hora_intento_gps')->nullable();
                $table->string('observacion', 255)->nullable();
                $table->timestamps();

                $table->index(['agente_id', 'fecha']);
                $table->unique(['agente_id', 'fecha']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
        Schema::dropIfExists('comisiones_planes');
        Schema::dropIfExists('comprobantes');
        Schema::dropIfExists('venta_lineas');
        Schema::dropIfExists('venta_equipos');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('reportes');
        Schema::dropIfExists('inventario_tiendas');
        Schema::dropIfExists('tiendas');
    }
};
