<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Migra los reportes históricos del sistema legacy al nuevo esquema.
 *
 * El seeder trabaja con la misma tabla `reportes` que comparten ambos sistemas,
 * asegurando que todos los campos requeridos por el nuevo modelo tengan valores
 * válidos y que la suma de efectivo se conserve exactamente.
 *
 * Seguridad de RAM: procesa de a CHUNK_SIZE filas para no cargar todo el dataset
 * en memoria — crítico en VPS con ≤ 1 GB de RAM.
 *
 * Uso:
 *   php artisan db:seed --class=MigrateReportesSeeder
 *
 * Si la validación falla el seeder lanza una excepción y revierte con rollback.
 */
class MigrateReportesSeeder extends Seeder
{
    private const CHUNK_SIZE = 50;

    public function run(): void
    {
        $this->command->info('▶  Iniciando MigrateReportesSeeder…');

        // ── 1. Captura la suma ANTES para validar integridad al final ────────
        $sumaAntes = (float) DB::table('reportes')->sum('total_dia');
        $totalRegistros = DB::table('reportes')->count();

        $this->command->info(sprintf(
            '   Registros encontrados: %d | suma total_dia ANTES: S/ %.2f',
            $totalRegistros,
            $sumaAntes
        ));

        if ($totalRegistros === 0) {
            $this->command->warn('   No hay registros en reportes. Nada que migrar.');
            return;
        }

        // ── 2. Migrar en chunks dentro de una transacción ────────────────────
        DB::beginTransaction();
        try {
            $procesados    = 0;
            $actualizados  = 0;
            $offset        = 0;

            while (true) {
                $chunk = DB::table('reportes')
                    ->orderBy('id')
                    ->offset($offset)
                    ->limit(self::CHUNK_SIZE)
                    ->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $r) {
                    $cambios = $this->normalizarReporte($r);
                    if (!empty($cambios)) {
                        DB::table('reportes')
                            ->where('id', $r->id)
                            ->update($cambios);
                        $actualizados++;
                    }
                    $procesados++;
                }

                $offset += self::CHUNK_SIZE;

                // Liberar memoria explícitamente en cada iteración
                unset($chunk);
            }

            // ── 3. Validación estricta de integridad ────────────────────────
            $sumaDespues = (float) DB::table('reportes')->sum('total_dia');
            $diferencia  = abs($sumaAntes - $sumaDespues);

            if ($diferencia > 0.001) {
                throw new \RuntimeException(sprintf(
                    'FALLO DE INTEGRIDAD: suma_antes=%.2f, suma_despues=%.2f, diferencia=%.4f. ' .
                    'Se revierte la migración.',
                    $sumaAntes,
                    $sumaDespues,
                    $diferencia
                ));
            }

            DB::commit();

            $this->command->info(sprintf(
                '✔  Migración completada. Procesados: %d | Actualizados: %d',
                $procesados,
                $actualizados
            ));
            $this->command->info(sprintf(
                '   suma total_dia DESPUÉS: S/ %.2f  (integridad ✔)',
                $sumaDespues
            ));

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->command->error('✘  ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Devuelve solo los campos que necesitan ser corregidos en este reporte.
     * Si el registro ya es válido retorna [] para no hacer UPDATE innecesario.
     */
    private function normalizarReporte(object $r): array
    {
        $cambios = [];

        // estado_edicion: campo nuevo, legacy puede tener NULL
        if (empty($r->estado_edicion)) {
            $cambios['estado_edicion'] = 'CERRADO';
        }

        // destino_efectivo: normalizar valores legacy no contemplados en el nuevo enum
        $destinosValidos = ['TIENDA', 'ENTREGADO', 'EN_CAJA', 'BANCO'];
        if (empty($r->destino_efectivo) || !in_array($r->destino_efectivo, $destinosValidos, true)) {
            // "ENTREGADO" es el destino más seguro si el campo viene vacío o con valor desconocido
            $cambios['destino_efectivo'] = 'ENTREGADO';
        }

        // requiere_aprobacion: asegurar que no sea NULL (el modelo lo espera como boolean)
        if (is_null($r->requiere_aprobacion)) {
            $diferencia = abs((float)($r->diferencia ?? 0));
            $cambios['requiere_aprobacion'] = $diferencia > 10 ? 1 : 0;
        }

        // diferencia calculada: verificar coherencia (no modifica total_dia, solo diff)
        if (!is_null($r->efectivo_entregado) && !is_null($r->efectivo_esperado)) {
            $diffCalculada = round((float)$r->efectivo_entregado - (float)$r->efectivo_esperado, 2);
            $diffActual    = round((float)($r->diferencia ?? 0), 2);
            if (abs($diffCalculada - $diffActual) > 0.01) {
                $cambios['diferencia'] = $diffCalculada;
            }
        }

        return $cambios;
    }
}
