<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\ComprobanteCorrelativo;
use App\Models\FacturacionConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Riesgo señalado en el informe de arquitectura (§3): «Correlativos SUNAT sin lock
 * robusto → doble número en concurrencia».
 *
 * La defensa tiene dos capas y aquí se prueban las dos:
 *  1. `ComprobanteCorrelativo::siguiente()` reserva el número dentro de una
 *     transacción, con `lockForUpdate()` sobre la fila de secuencia (emisor, serie).
 *  2. El índice único `uq_cola_emisor_serie_correlativo` impide persistir el mismo
 *     número dos veces aunque la capa 1 fallara.
 *
 * PHPUnit no da hilos: la capa 1 se verifica por su contrato observable (la lectura
 * de la secuencia ocurre dentro de una transacción, y el número no se consume si la
 * transacción externa hace rollback), no arrancando dos procesos reales.
 */
class ComprobanteCorrelativoTest extends TestCase
{
    use RefreshDatabase;

    private function emisor(array $extra = []): FacturacionConfig
    {
        return FacturacionConfig::factory()->create($extra);
    }

    // ── Capa 1: reserva transaccional con lock ──────────────────────────────

    public function test_los_correlativos_son_consecutivos_y_sin_duplicados(): void
    {
        $emisor = $this->emisor();

        $numeros = [];
        for ($i = 0; $i < 100; $i++) {
            $numeros[] = ComprobanteCorrelativo::siguiente($emisor, 'B001');
        }

        $this->assertSame(range(1, 100), $numeros);
        $this->assertCount(100, array_unique($numeros));
    }

    /**
     * Reservas intercaladas de dos «workers» sobre la misma serie: cada una abre y
     * cierra su transacción. Ninguno puede leer un `ultimo_correlativo` obsoleto.
     */
    public function test_reservas_intercaladas_sobre_la_misma_serie_no_se_duplican(): void
    {
        $emisor = $this->emisor();
        $numeros = [];

        for ($ronda = 0; $ronda < 50; $ronda++) {
            $numeros[] = ComprobanteCorrelativo::siguiente($emisor, 'B001'); // worker A
            $numeros[] = ComprobanteCorrelativo::siguiente($emisor, 'B001'); // worker B
        }

        $this->assertCount(100, array_unique($numeros), 'Se repitió un correlativo.');
        $this->assertSame(100, ComprobanteCorrelativo::query()
            ->where('facturacion_config_id', $emisor->id)
            ->where('serie', 'B001')
            ->value('ultimo_correlativo'));
    }

    public function test_la_secuencia_se_lee_dentro_de_una_transaccion(): void
    {
        $emisor = $this->emisor();
        $nivelesEnLectura = [];

        DB::listen(function ($query) use (&$nivelesEnLectura) {
            if (str_contains($query->sql, 'comprobantes_correlativos') && str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $nivelesEnLectura[] = DB::connection()->transactionLevel();
            }
        });

        ComprobanteCorrelativo::siguiente($emisor, 'B001');

        $this->assertNotEmpty($nivelesEnLectura, 'No se leyó la fila de secuencia.');
        $this->assertGreaterThan(
            0,
            min($nivelesEnLectura),
            'La secuencia se leyó fuera de transacción: el lock no protege nada.'
        );
    }

    public function test_el_rollback_de_la_transaccion_externa_no_consume_el_numero(): void
    {
        $emisor = $this->emisor();

        $this->assertSame(1, ComprobanteCorrelativo::siguiente($emisor, 'B001'));

        try {
            DB::transaction(function () use ($emisor) {
                ComprobanteCorrelativo::siguiente($emisor, 'B001'); // reservaría el 2

                throw new \RuntimeException('la emisión explotó antes de commitear');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        // El 2 vuelve a estar disponible: la reserva participó de la transacción externa.
        $this->assertSame(2, ComprobanteCorrelativo::siguiente($emisor, 'B001'));
    }

    public function test_cada_emisor_y_serie_lleva_su_propia_secuencia(): void
    {
        $emisorA = $this->emisor(['tienda_id' => null]);
        $emisorB = $this->emisor(['tienda_id' => 'PUNDA50']);

        $this->assertSame(1, ComprobanteCorrelativo::siguiente($emisorA, 'B001'));
        $this->assertSame(2, ComprobanteCorrelativo::siguiente($emisorA, 'B001'));
        $this->assertSame(1, ComprobanteCorrelativo::siguiente($emisorA, 'F001'));
        $this->assertSame(1, ComprobanteCorrelativo::siguiente($emisorB, 'B001'));

        $this->assertSame(3, ComprobanteCorrelativo::query()->count());
    }

    public function test_la_serie_vacia_es_un_error_de_programacion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComprobanteCorrelativo::siguiente($this->emisor(), '  ');
    }

    // ── Capa 2: el índice único como red de seguridad ───────────────────────

    public function test_la_bd_rechaza_dos_comprobantes_con_el_mismo_emisor_serie_y_correlativo(): void
    {
        $emisor = $this->emisor();

        ComprobanteCola::factory()->create([
            'ticket_id'             => 1,
            'facturacion_config_id' => $emisor->id,
            'serie'                 => 'B001',
            'correlativo'           => 7,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ComprobanteCola::factory()->create([
            'ticket_id'             => 2,
            'facturacion_config_id' => $emisor->id,
            'serie'                 => 'B001',
            'correlativo'           => 7,
        ]);
    }

    public function test_dos_emisores_pueden_usar_el_mismo_numero(): void
    {
        $emisorA = $this->emisor(['tienda_id' => null]);
        $emisorB = $this->emisor(['tienda_id' => 'PUNDA50']);

        foreach ([$emisorA, $emisorB] as $i => $emisor) {
            ComprobanteCola::factory()->create([
                'ticket_id'             => $i + 1,
                'facturacion_config_id' => $emisor->id,
                'serie'                 => 'B001',
                'correlativo'           => 7,
            ]);
        }

        $this->assertSame(2, ComprobanteCola::query()->where('correlativo', 7)->count());
    }

    /** Las filas encoladas sin número aún no compiten por el índice único. */
    public function test_muchas_filas_pendientes_sin_correlativo_conviven(): void
    {
        ComprobanteCola::factory()->count(3)->create(['correlativo' => null, 'serie' => null]);

        $this->assertSame(3, ComprobanteCola::query()->whereNull('correlativo')->count());
    }

    // ── Integración con la cola ─────────────────────────────────────────────

    public function test_asignar_correlativo_toma_la_serie_del_emisor_y_reserva_el_numero(): void
    {
        $emisor = $this->emisor(['serie_factura' => 'F007']);
        $cola = ComprobanteCola::factory()->factura()->create(['ticket_id' => 1]);

        $cola->asignarCorrelativo($emisor);

        $this->assertSame('F007', $cola->serie);
        $this->assertSame(1, $cola->correlativo);
        $this->assertSame($emisor->id, $cola->facturacion_config_id);
        $this->assertSame('F007-00000001', $cola->numero_completo);
    }

    /** Un reintento NO debe quemar un número nuevo. */
    public function test_asignar_correlativo_es_idempotente_entre_reintentos(): void
    {
        $emisor = $this->emisor();
        $cola = ComprobanteCola::factory()->create(['ticket_id' => 1]);

        $cola->asignarCorrelativo($emisor);
        $primero = $cola->correlativo;

        $cola->registrarError('API caída');
        $cola->asignarCorrelativo($emisor);

        $this->assertSame($primero, $cola->fresh()->correlativo);
        $this->assertSame(
            1,
            ComprobanteCorrelativo::query()->where('facturacion_config_id', $emisor->id)->value('ultimo_correlativo'),
            'El reintento consumió un correlativo nuevo.'
        );
    }

    public function test_dos_comprobantes_del_mismo_emisor_reciben_numeros_distintos(): void
    {
        $emisor = $this->emisor();

        $uno = ComprobanteCola::factory()->create(['ticket_id' => 1])->asignarCorrelativo($emisor);
        $dos = ComprobanteCola::factory()->create(['ticket_id' => 2])->asignarCorrelativo($emisor);

        $this->assertSame(1, $uno->correlativo);
        $this->assertSame(2, $dos->correlativo);
    }
}
