<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * P2 de rendimiento: índices compuestos para las consultas calientes detectadas
 * en la auditoría (reportes/ventas por vendedor y fecha, cuadre Bipay, bandeja
 * de WhatsApp). `transacciones_bipay` es una tabla legacy sin migración propia
 * en este repo, así que se resuelve su columna de fecha en runtime (creado_en
 * o created_at) y se omite si la tabla no existe en el entorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ventas') && ! $this->indexExists('ventas', 'idx_ventas_vendedor_creado')) {
            Schema::table('ventas', function ($table) {
                $table->index(['vendedor_id', 'creado_en'], 'idx_ventas_vendedor_creado');
            });
        }

        if (Schema::hasTable('transacciones_bipay')) {
            $columnaFecha = Schema::hasColumn('transacciones_bipay', 'creado_en') ? 'creado_en'
                : (Schema::hasColumn('transacciones_bipay', 'created_at') ? 'created_at' : null);

            if ($columnaFecha !== null
                && Schema::hasColumn('transacciones_bipay', 'tipo_operacion')
                && ! $this->indexExists('transacciones_bipay', 'idx_transacciones_bipay_fecha_tipo')) {
                Schema::table('transacciones_bipay', function ($table) use ($columnaFecha) {
                    $table->index([$columnaFecha, 'tipo_operacion'], 'idx_transacciones_bipay_fecha_tipo');
                });
            }
        }

        if (Schema::hasTable('whatsapp_chats') && ! $this->indexExists('whatsapp_chats', 'idx_whatsapp_chats_cuenta_ultimo_mensaje')) {
            Schema::table('whatsapp_chats', function ($table) {
                $table->index(['cuenta_id', 'ultimo_mensaje_at'], 'idx_whatsapp_chats_cuenta_ultimo_mensaje');
            });
        }

        if (Schema::hasTable('whatsapp_mensajes') && ! $this->indexExists('whatsapp_mensajes', 'idx_whatsapp_mensajes_chat_timestamp')) {
            Schema::table('whatsapp_mensajes', function ($table) {
                $table->index(['chat_id', 'timestamp'], 'idx_whatsapp_mensajes_chat_timestamp');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas') && $this->indexExists('ventas', 'idx_ventas_vendedor_creado')) {
            Schema::table('ventas', function ($table) {
                $table->dropIndex('idx_ventas_vendedor_creado');
            });
        }

        if (Schema::hasTable('transacciones_bipay') && $this->indexExists('transacciones_bipay', 'idx_transacciones_bipay_fecha_tipo')) {
            Schema::table('transacciones_bipay', function ($table) {
                $table->dropIndex('idx_transacciones_bipay_fecha_tipo');
            });
        }

        if (Schema::hasTable('whatsapp_chats') && $this->indexExists('whatsapp_chats', 'idx_whatsapp_chats_cuenta_ultimo_mensaje')) {
            Schema::table('whatsapp_chats', function ($table) {
                $table->dropIndex('idx_whatsapp_chats_cuenta_ultimo_mensaje');
            });
        }

        if (Schema::hasTable('whatsapp_mensajes') && $this->indexExists('whatsapp_mensajes', 'idx_whatsapp_mensajes_chat_timestamp')) {
            Schema::table('whatsapp_mensajes', function ($table) {
                $table->dropIndex('idx_whatsapp_mensajes_chat_timestamp');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->pluck('name')->contains($indexName);
    }
};
