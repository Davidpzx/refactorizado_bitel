<?php

namespace App\Services;

use App\Models\Agente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cambios de agente en `historial_agentes` (paridad legacy
 * gerencia/editar_agente.php y gerencia/ver_agente.php). Escritura tolerante: si la tabla no
 * existe (entorno mínimo sin migrar), no rompe el flujo de actualización.
 */
class HistorialAgenteService
{
    /**
     * Audita la transición de estado (CESE/REINGRESO) y los cambios de tienda/horario tras un
     * update de agente. Compara el snapshot previo contra el estado ya persistido.
     */
    public function auditarActualizacion(Agente $antes, Agente $despues, ?int $usuarioId): void
    {
        if (! $this->tablaDisponible()) {
            return;
        }

        // Estado → CESE (a estado ≠ ACTIVO: INACTIVO o BAJA) / REINGRESO (a ACTIVO)
        if ((string) $antes->estado !== (string) $despues->estado) {
            $esActivo = $despues->estado === 'ACTIVO';
            $this->insertar([
                'id_agente'          => $despues->id,
                'estado'             => $despues->estado,
                'tipo_cambio'        => $esActivo ? 'REINGRESO' : 'CESE',
                'clasificacion_baja' => $esActivo ? null : $despues->clasificacion_baja,
                'observacion'        => $esActivo ? null : $this->observacionBaja($despues),
                'registrado_por'     => $usuarioId,
            ]);
        }

        // Tienda base
        if ((string) $antes->tienda_base !== (string) $despues->tienda_base) {
            $this->insertar([
                'id_agente'      => $despues->id,
                'estado'         => $despues->estado,
                'tipo_cambio'    => 'TIENDA',
                'campo_anterior' => $antes->tienda_base,
                'campo_nuevo'    => $despues->tienda_base,
                'registrado_por' => $usuarioId,
            ]);
        }

        // Horario (entrada / salida / día de descanso)
        $horarioAntes   = $this->horarioStr($antes);
        $horarioDespues = $this->horarioStr($despues);
        if ($horarioAntes !== $horarioDespues) {
            $this->insertar([
                'id_agente'      => $despues->id,
                'estado'         => $despues->estado,
                'tipo_cambio'    => 'HORARIO',
                'campo_anterior' => $horarioAntes,
                'campo_nuevo'    => $horarioDespues,
                'registrado_por' => $usuarioId,
            ]);
        }
    }

    /**
     * Audita la edición de la ficha RRHH (nombre/teléfono/correo/pensión). Solo escribe FICHA si
     * hubo diferencia en alguno de los campos (paridad ver_agente.php:138-157).
     */
    public function auditarFicha(Agente $antes, Agente $despues, ?int $usuarioId): void
    {
        if (! $this->tablaDisponible()) {
            return;
        }

        $campos = ['nombres', 'telefono', 'correo', 'sistema_pension'];
        $anterior = [];
        $nuevo    = [];
        $huboCambio = false;

        foreach ($campos as $campo) {
            $va = $antes->getAttribute($campo);
            $vd = $despues->getAttribute($campo);
            if ((string) $va !== (string) $vd) {
                $huboCambio = true;
            }
            $anterior[$campo] = $va;
            $nuevo[$campo]    = $vd;
        }

        if (! $huboCambio) {
            return;
        }

        $this->insertar([
            'id_agente'      => $despues->id,
            'estado'         => $despues->estado,
            'tipo_cambio'    => 'FICHA',
            'campo_anterior' => json_encode($anterior, JSON_UNESCAPED_UNICODE),
            'campo_nuevo'    => json_encode($nuevo, JSON_UNESCAPED_UNICODE),
            'registrado_por' => $usuarioId,
        ]);
    }

    private function tablaDisponible(): bool
    {
        return Schema::hasTable('historial_agentes');
    }

    private function insertar(array $fila): void
    {
        DB::table('historial_agentes')->insert(array_merge(
            ['fecha_registro' => now()],
            $fila
        ));
    }

    private function horarioStr(Agente $a): string
    {
        return sprintf(
            'E:%s S:%s D:%s',
            $a->hora_ingreso ?? '',
            $a->hora_salida ?? '',
            $a->dia_descanso ?? ''
        );
    }

    private function observacionBaja(Agente $a): ?string
    {
        $partes = array_filter([$a->motivo_baja, $a->observacion], fn ($v) => filled($v));

        return $partes ? implode(' | ', $partes) : null;
    }
}
