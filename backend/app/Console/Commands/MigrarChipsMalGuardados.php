<?php

namespace App\Console\Commands;

use App\Models\InventarioChip;
use App\Models\InventarioTienda;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrarChipsMalGuardados extends Command
{
    protected $signature = 'inventario:migrar-chips-mal-guardados {--force : Ejecuta el movimiento de verdad (por defecto es solo dry-run)}';

    protected $description = 'Mueve filas tipo=CHIP guardadas por error en inventario_tiendas hacia inventario_chips';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $filas = InventarioTienda::where('tipo', 'CHIP')->orderBy('id')->get();

        if ($filas->isEmpty()) {
            $this->info('Sin filas CHIP mal guardadas en inventario_tiendas. Nada que migrar.');
            return self::SUCCESS;
        }

        $movidas = 0;
        $omitidas = 0;

        foreach ($filas as $fila) {
            $tiendaCodigo = (string) $fila->tienda_id;
            $tiendaDbId = DB::table('tiendas')->where('codigo', $tiendaCodigo)->value('id');

            if (! $tiendaDbId) {
                $this->warn("  [OMITIDA] #{$fila->id} tienda '{$tiendaCodigo}' no existe en tabla tiendas.");
                logger()->warning('[inventario:migrar-chips-mal-guardados] omitida', ['id' => $fila->id, 'tienda_id' => $tiendaCodigo]);
                $omitidas++;
                continue;
            }

            $linea = "  [{$fila->id}] {$tiendaCodigo} -> tienda_id={$tiendaDbId}, cantidad={$fila->cantidad}";

            if (! $force) {
                $this->line("  [DRY-RUN]{$linea}");
                $movidas++;
                continue;
            }

            DB::transaction(function () use ($tiendaDbId, $tiendaCodigo, $fila) {
                $existente = InventarioChip::where('tienda_id', $tiendaDbId)
                    ->where('tienda_origen', $tiendaCodigo)
                    ->first();

                if ($existente) {
                    $existente->increment('stock_actual', $fila->cantidad);
                } else {
                    InventarioChip::create([
                        'tienda_id'     => $tiendaDbId,
                        'tienda_origen' => $tiendaCodigo,
                        'tipo_chip'     => 'FÍSICO',
                        'stock_actual'  => $fila->cantidad,
                    ]);
                }

                $fila->delete();
            });

            $this->line("  [MOVIDA]{$linea}");
            logger()->info('[inventario:migrar-chips-mal-guardados] movida', [
                'id' => $fila->id, 'tienda_id' => $tiendaDbId, 'tienda_origen' => $tiendaCodigo, 'cantidad' => $fila->cantidad,
            ]);
            $movidas++;
        }

        $modo = $force ? 'Movidas' : '[DRY-RUN] Se moverían';
        $this->info("{$modo}: {$movidas} | Omitidas (sin tienda): {$omitidas}");

        if (! $force && $movidas > 0) {
            $this->comment('Ejecuta con --force para aplicar el movimiento de verdad.');
        }

        return self::SUCCESS;
    }
}
