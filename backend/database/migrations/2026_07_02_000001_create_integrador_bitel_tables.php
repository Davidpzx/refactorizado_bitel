<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puerto del ecosistema Integrador Bitel / Cuadre ERP / Auditoría Bipay del legacy sis_bipay.
 * Todas las creaciones son idempotentes (guardadas por hasTable/hasColumn) porque en
 * producción varias de estas tablas ya existen creadas por el legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Credenciales Bitel por tienda (integrador) ────────────────────────
        if (! Schema::hasTable('integrador_credenciales')) {
            Schema::create('integrador_credenciales', function (Blueprint $t) {
                $t->increments('id');
                $t->string('tienda_codigo', 20)->unique('uk_integrador_tienda');
                $t->string('bitel_username', 80);
                $t->text('bitel_password_enc');
                $t->string('channel_type', 4)->default('1');
                $t->integer('sync_intervalo_min')->default(15);
                $t->char('agente_token_hash', 64);
                $t->boolean('activo')->default(true);
                $t->dateTime('last_config_fetch')->nullable();
                $t->dateTime('last_sync_at')->nullable();
                $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->dateTime('creado_en')->useCurrent();
            });
        }

        // ── Consolidado diario de movimientos Bitel (inyectado por el agente) ─
        if (! Schema::hasTable('bitel_movimientos_diarios')) {
            Schema::create('bitel_movimientos_diarios', function (Blueprint $t) {
                $t->increments('id');
                $t->string('tienda_codigo', 20);
                $t->date('fecha');
                foreach (['postpago', 'paquetes', 'recargas', 'prepago', 'portabilidad', 'reembolsos', 'otros', 'total_neto'] as $col) {
                    $t->decimal($col, 10, 2)->default(0);
                }
                $t->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
                $t->unique(['tienda_codigo', 'fecha'], 'uk_tienda_fecha_mov');
            });
        }

        // ── Detalle línea-por-línea del scraping Bitel ────────────────────────
        if (! Schema::hasTable('bitel_operaciones_detalle')) {
            Schema::create('bitel_operaciones_detalle', function (Blueprint $t) {
                $t->increments('id');
                $t->string('tienda_codigo', 20);
                $t->dateTime('fecha_hora');
                $t->string('tipo_operacion', 100)->nullable();
                $t->string('descripcion', 255)->nullable();
                $t->decimal('monto', 10, 2)->nullable();
                $t->string('estado', 50)->default('PROCESADO');
                $t->string('codigo_personal', 60)->nullable();
                $t->unique(['tienda_codigo', 'fecha_hora', 'descripcion', 'monto'], 'idx_unique_mov');
            });
        } elseif (! Schema::hasColumn('bitel_operaciones_detalle', 'codigo_personal')) {
            Schema::table('bitel_operaciones_detalle', fn (Blueprint $t) => $t->string('codigo_personal', 60)->nullable());
        }

        // ── Apoyos entre tiendas (una tienda vende con la cuenta Bitel de otra) ─
        if (! Schema::hasTable('bitel_apoyos')) {
            Schema::create('bitel_apoyos', function (Blueprint $t) {
                $t->increments('id');
                $t->date('fecha');
                $t->string('tienda_origen', 20);
                $t->string('tienda_destino', 20);
                $t->unsignedInteger('confirmado_por')->nullable();
                $t->text('notas')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['fecha', 'tienda_origen', 'tienda_destino'], 'uk_apoyo_dia');
            });
        }

        // ── Cola de histórico Bitel (fechas pasadas a re-extraer) ────────────
        if (! Schema::hasTable('bitel_historico_queue')) {
            Schema::create('bitel_historico_queue', function (Blueprint $t) {
                $t->increments('id');
                $t->date('fecha')->unique('uq_fecha');
                $t->enum('estado', ['PENDIENTE', 'PROCESANDO', 'LISTO', 'ERROR'])->default('PENDIENTE');
                $t->integer('movs_procesados')->default(0);
                $t->integer('solicitado_por')->nullable();
                $t->dateTime('fecha_solicitud')->useCurrent();
                $t->dateTime('fecha_procesado')->nullable();
                $t->string('mensaje', 255)->nullable();
            });
        }

        // ── Cola de extracciones on-demand (deudas / morosidad) ───────────────
        if (! Schema::hasTable('solicitudes_extraccion')) {
            Schema::create('solicitudes_extraccion', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('periodo', 7);
                $t->string('tipo', 20)->default('deudas');
                $t->enum('estado', ['PENDIENTE', 'PROCESANDO', 'LISTO', 'ERROR'])->default('PENDIENTE');
                $t->integer('total_lineas')->default(0);
                $t->string('mensaje', 255)->nullable();
                $t->integer('solicitado_por')->nullable();
                $t->dateTime('fecha_solicitud')->useCurrent();
                $t->dateTime('fecha_procesado')->nullable();
                $t->unique(['periodo', 'tipo'], 'uq_periodo_tipo');
                $t->index('estado', 'idx_estado');
            });
        }

        // ── Estado de líneas de clientes (churn / morosidad / altas) ─────────
        // El legacy tenía una versión vieja con columna isdn_cliente que se descarta.
        if (Schema::hasTable('clientes_estado') && Schema::hasColumn('clientes_estado', 'isdn_cliente')) {
            Schema::drop('clientes_estado');
        }
        if (! Schema::hasTable('clientes_estado')) {
            Schema::create('clientes_estado', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('numero_linea', 20);
                $t->string('dni_cliente', 12)->nullable();
                $t->string('cod_tienda', 10)->nullable();
                $t->string('plan', 100)->nullable();
                $t->date('fecha_alta')->nullable();
                $t->enum('estado_linea', ['ACTIVO', 'SUSPENDIDO', 'BAJA'])->default('ACTIVO');
                $t->string('estado_bloqueo', 20)->default('NORMAL');
                $t->integer('dias_mora')->default(0);
                $t->decimal('monto_deuda', 10, 2)->default(0);
                $t->boolean('es_churn')->default(false);
                $t->string('periodo', 7);
                $t->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
                $t->unique(['numero_linea', 'periodo'], 'uq_linea_periodo');
                $t->index('cod_tienda', 'idx_tienda');
                $t->index('periodo', 'idx_periodo');
            });
        }

        if (! Schema::hasTable('lineas_morosidad')) {
            Schema::create('lineas_morosidad', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('numero_linea', 20);
                $t->string('cliente', 150)->nullable();
                $t->string('cod_tienda', 10)->nullable();
                $t->string('plan', 100)->nullable();
                $t->date('fecha_alta')->nullable();
                $t->enum('estado_linea', ['ACTIVO', 'SUSPENDIDO', 'BAJA'])->default('ACTIVO');
                $t->date('fecha_cancelacion')->nullable();
                $t->integer('dias_mora')->default(0);
                $t->decimal('monto_deuda', 10, 2)->default(0);
                $t->boolean('es_churn')->default(false);
                $t->string('periodo', 7);
                $t->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
                $t->unique(['numero_linea', 'periodo'], 'uq_linea_periodo_mor');
                $t->index('cod_tienda', 'idx_tienda_mor');
                $t->index('periodo', 'idx_periodo_mor');
            });
        }

        // ── Clasificación de tesorería (efecto de cada operación en el cuadre) ─
        if (! Schema::hasTable('tesoreria_clasificacion')) {
            Schema::create('tesoreria_clasificacion', function (Blueprint $t) {
                $t->increments('id');
                $t->string('codigo_operacion', 60)->unique('uk_codigo');
                $t->string('etiqueta', 120);
                $t->enum('efecto_efectivo', ['POS', 'NEG', 'CERO'])->default('CERO');
                $t->enum('efecto_digital', ['POS', 'NEG', 'CERO'])->default('CERO');
                $t->boolean('incluir_en_cuadre')->default(true);
                $t->boolean('tiene_bitel')->default(false);
                $t->enum('origen', ['ERP', 'BITEL'])->default('BITEL');
                $t->boolean('activo')->default(true);
            });
        } elseif (! Schema::hasColumn('tesoreria_clasificacion', 'tiene_bitel')) {
            Schema::table('tesoreria_clasificacion', fn (Blueprint $t) => $t->boolean('tiene_bitel')->default(false)->after('incluir_en_cuadre'));
        }
        $this->seedTesoreria();

        // ── Auditoría de cierres Bipay (cruce declarado vs scrapeado) ─────────
        if (! Schema::hasTable('auditoria_cierres')) {
            Schema::create('auditoria_cierres', function (Blueprint $t) {
                $t->increments('id');
                $t->string('tienda_codigo', 20);
                $t->date('fecha');
                $t->integer('reporte_id')->nullable();
                $t->decimal('saldo_bipay_real', 10, 2)->default(0);
                $t->decimal('ventas_sistema', 10, 2)->default(0);
                $t->decimal('diferencia', 10, 2)->default(0);
                $t->enum('estado', ['CUADRADO', 'DESCUADRADO', 'AJUSTADO'])->default('DESCUADRADO');
                $t->text('observacion_ajuste')->nullable();
                $t->integer('ajustado_por')->nullable();
                $t->dateTime('fecha_ajuste')->nullable();
                // Slice caja/billetera del legacy (cierre a ciegas)
                $t->decimal('efectivo_esperado', 10, 2)->default(0);
                $t->decimal('efectivo_declarado', 10, 2)->default(0);
                $t->decimal('diferencia_efectivo', 10, 2)->default(0);
                $t->decimal('saldo_digital_declarado', 10, 2)->default(0);
                $t->dateTime('blind_declarado_en')->nullable();
                $t->boolean('bloqueado')->default(false);
                $t->timestamp('creado_en')->useCurrent();
                $t->unique(['tienda_codigo', 'fecha'], 'uk_tienda_fecha');
            });
        } else {
            foreach ([
                'efectivo_esperado'       => fn (Blueprint $t) => $t->decimal('efectivo_esperado', 10, 2)->default(0),
                'efectivo_declarado'      => fn (Blueprint $t) => $t->decimal('efectivo_declarado', 10, 2)->default(0),
                'diferencia_efectivo'     => fn (Blueprint $t) => $t->decimal('diferencia_efectivo', 10, 2)->default(0),
                'saldo_digital_declarado' => fn (Blueprint $t) => $t->decimal('saldo_digital_declarado', 10, 2)->default(0),
                'blind_declarado_en'      => fn (Blueprint $t) => $t->dateTime('blind_declarado_en')->nullable(),
                'bloqueado'               => fn (Blueprint $t) => $t->boolean('bloqueado')->default(false),
            ] as $col => $adder) {
                if (! Schema::hasColumn('auditoria_cierres', $col)) {
                    Schema::table('auditoria_cierres', $adder);
                }
            }
        }

        // ── sys_config (webhooks Discord/Slack) ───────────────────────────────
        if (! Schema::hasTable('sys_config')) {
            Schema::create('sys_config', function (Blueprint $t) {
                $t->string('clave', 100)->primary();
                $t->text('valor')->nullable();
                $t->string('descripcion', 255)->nullable();
                $t->dateTime('actualizado_en')->useCurrent()->useCurrentOnUpdate();
            });
        }
        DB::table('sys_config')->insertOrIgnore([
            ['clave' => 'bipay_webhook_url',    'valor' => '',        'descripcion' => 'URL del Webhook de Discord o Slack para alertas de descuadre'],
            ['clave' => 'bipay_webhook_tipo',   'valor' => 'discord', 'descripcion' => 'Tipo de webhook: discord o slack'],
            ['clave' => 'bipay_webhook_umbral', 'valor' => '5.00',    'descripcion' => 'Monto minimo de descuadre en Soles para disparar la alerta'],
        ]);

        // ── Log de resolución de conflictos de atribución (captador vs vendedor) ─
        if (! Schema::hasTable('log_resolucion_atribucion')) {
            Schema::create('log_resolucion_atribucion', function (Blueprint $t) {
                $t->increments('id');
                $t->string('dni_cliente', 15);
                $t->dateTime('ticket_creado_en');
                $t->string('vendedor_original', 100);
                $t->string('captador_original', 100);
                $t->enum('decision', ['CAPTADOR', 'VENDEDOR']);
                $t->integer('resuelto_por');
                $t->dateTime('resuelto_en')->useCurrent();
                $t->unique(['dni_cliente', 'ticket_creado_en'], 'uq_ticket_disputa');
            });
        }

        // ── CRM ligero de clientes (Cliente Activo del cuadre) ────────────────
        if (! Schema::hasTable('crm_clientes')) {
            Schema::create('crm_clientes', function (Blueprint $t) {
                $t->increments('id');
                $t->string('dni', 8)->unique('idx_dni_unico');
                $t->string('nombres', 150);
                $t->string('apellidos', 150);
                $t->string('telefono', 15)->nullable();
                $t->string('operadora', 20)->default('Bitel');
                $t->dateTime('fecha_registro')->useCurrent();
            });
        } elseif (! Schema::hasColumn('crm_clientes', 'operadora')) {
            Schema::table('crm_clientes', fn (Blueprint $t) => $t->string('operadora', 20)->default('Bitel')->after('telefono'));
        }

        if (! Schema::hasTable('crm_interacciones')) {
            Schema::create('crm_interacciones', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('cliente_id');
                $t->string('tienda_codigo', 50);
                $t->string('agente_nombre', 150);
                $t->string('tipo_operacion', 50);
                $t->string('producto_interes', 100)->nullable();
                $t->string('motivo_rechazo', 100)->nullable();
                $t->dateTime('fecha_hora')->useCurrent();
                $t->text('observacion')->nullable();
                $t->enum('estado_lead', ['CONTACTADO', 'INTERESADO', 'CERRADO', 'NO_CONTESTA'])->default('CONTACTADO');
                $t->enum('fuente_registro', ['RENIEC_API', 'MANUAL_CON_FALLBACK'])->default('RENIEC_API');
                $t->index('cliente_id', 'fk_interaccion_cliente');
            });
        } else {
            foreach ([
                'producto_interes' => fn (Blueprint $t) => $t->string('producto_interes', 100)->nullable(),
                'motivo_rechazo'   => fn (Blueprint $t) => $t->string('motivo_rechazo', 100)->nullable(),
            ] as $col => $adder) {
                if (! Schema::hasColumn('crm_interacciones', $col)) {
                    Schema::table('crm_interacciones', $adder);
                }
            }
        }

        // ── Documentos del agente (foto perfil / foto DNI en base64) ──────────
        if (Schema::hasTable('agentes')) {
            foreach (['foto_perfil', 'foto_dni'] as $col) {
                if (! Schema::hasColumn('agentes', $col)) {
                    Schema::table('agentes', fn (Blueprint $t) => $t->longText($col)->nullable());
                }
            }
        }

        // ── Columnas auxiliares que el flujo M2M necesita ──────────────────────
        if (Schema::hasTable('cuentas_bipay') && ! Schema::hasColumn('cuentas_bipay', 'estado_api')) {
            Schema::table('cuentas_bipay', fn (Blueprint $t) => $t->string('estado_api', 50)->default('OK'));
        }
        if (Schema::hasTable('tiendas') && ! Schema::hasColumn('tiendas', 'cuenta_bipay_id')) {
            Schema::table('tiendas', fn (Blueprint $t) => $t->integer('cuenta_bipay_id')->nullable());
        }
    }

    private function seedTesoreria(): void
    {
        $rows = [
            ['postpago',            'Postpago',               'POS', 'NEG',  1, 1, 'ERP'],
            ['chip_prepago',        'Chip prepago',           'POS', 'CERO', 1, 0, 'ERP'],
            ['equipos_accesorios',  'Equipos/Accesorios',     'POS', 'CERO', 1, 0, 'ERP'],
            ['paquetes',            'Paquetes',               'POS', 'NEG',  1, 1, 'ERP'],
            ['recarga_bipay',       'Recarga BiPay',          'POS', 'NEG',  1, 0, 'ERP'],
            ['pagos_recargas',      'Pagos y recargas',       'POS', 'NEG',  1, 1, 'ERP'],
            ['tickets_tusamy',      'Ticket Tusamy',          'POS', 'NEG',  1, 1, 'ERP'],
            ['VENTA_TICKET_TUSAMI', 'Ticket Tusamy (Bitel)',  'POS', 'NEG',  1, 0, 'BITEL'],
            ['RECHARGE',            'Recarga (Bitel)',        'POS', 'NEG',  1, 0, 'BITEL'],
            ['RECARGA_BIPAY',       'Recarga BiPay (Bitel)',  'POS', 'NEG',  1, 0, 'BITEL'],
            ['RECARGA_LINEA_BITEL', 'Recarga de línea Bitel', 'POS', 'NEG',  1, 0, 'BITEL'],
            ['pago_krece',          'Pago Krece',             'POS', 'CERO', 0, 0, 'ERP'],
            ['pago_payjoy',         'Pago PayJoy',            'POS', 'CERO', 0, 0, 'ERP'],
            ['pago_servicio',       'Pago de servicios',      'POS', 'NEG',  1, 1, 'ERP'],
            ['retiro_bipay',        'Retiro/Cashout',         'NEG', 'POS',  0, 0, 'ERP'],
        ];
        DB::table('tesoreria_clasificacion')->insertOrIgnore(array_map(fn ($r) => [
            'codigo_operacion'  => $r[0],
            'etiqueta'          => $r[1],
            'efecto_efectivo'   => $r[2],
            'efecto_digital'    => $r[3],
            'incluir_en_cuadre' => $r[4],
            'tiene_bitel'       => $r[5],
            'origen'            => $r[6],
            'activo'            => 1,
        ], $rows));
    }

    public function down(): void
    {
        // Tablas compartidas con el legacy: no se eliminan en rollback por seguridad.
    }
};
