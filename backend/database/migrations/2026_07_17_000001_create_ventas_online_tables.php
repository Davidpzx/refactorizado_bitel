<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App Venta Online — registro de ventas creadas desde la app (delivery de chips /
 * planes online), incumplimientos (apertura de Activa Bitel sin registro previo) y
 * metadatos de versión del APK. La app se autentica por Sanctum (no hay tabla de
 * tokens propia como en rolando).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas_online', function (Blueprint $table) {
            $table->id();
            $table->string('agente_ref', 120);
            $table->string('tienda_codigo', 40);
            $table->char('dni', 8);
            $table->string('nombres', 160);
            $table->string('telefono', 15)->nullable();
            $table->string('operador_origen', 30);
            $table->enum('tipo', ['delivery_chip', 'plan_online']);
            $table->string('plan_ofrecido', 120)->nullable();
            $table->text('notas')->nullable();
            $table->enum('estado', ['pendiente', 'exitoso', 'fallido'])->default('pendiente');
            $table->string('motivo_falla', 200)->nullable();
            $table->unsignedBigInteger('crm_cliente_id')->nullable();
            $table->string('origen', 20)->default('app');
            $table->timestamps();

            $table->index(['tienda_codigo', 'created_at'], 'idx_vo_tienda_fecha');
            $table->index('estado', 'idx_vo_estado');
            $table->index('dni', 'idx_vo_dni');
            $table->index(['agente_ref', 'created_at'], 'idx_vo_agente_fecha');
        });

        Schema::create('ventas_online_incumplimientos', function (Blueprint $table) {
            $table->id();
            $table->string('agente_ref', 120);
            $table->string('tienda_codigo', 40);
            $table->timestamp('detectado_en')->useCurrent();
            $table->string('detalle', 255)->nullable();

            $table->index(['tienda_codigo', 'detectado_en'], 'idx_voi_tienda_fecha');
        });

        Schema::create('app_venta_version', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->string('min_version', 20)->nullable();
            $table->date('fecha_limite')->nullable();
            $table->string('ota_bundle_version', 40)->nullable();
            $table->string('ota_url_zip', 255)->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->timestamp('actualizado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_venta_version');
        Schema::dropIfExists('ventas_online_incumplimientos');
        Schema::dropIfExists('ventas_online');
    }
};
