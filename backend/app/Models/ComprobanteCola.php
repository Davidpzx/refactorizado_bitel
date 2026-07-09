<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cola de comprobantes electrónicos. Paridad de `comprobantes_cola` del legacy.
 *
 * Regla de oro: el request web SOLO encola. La emisión contra la API externa de
 * facturación la hace un worker que drena la cola con backoff exponencial.
 *
 * El `payload` es un SNAPSHOT FISCAL CONGELADO: se arma una vez, al encolar, y no
 * se recalcula en los reintentos. Si los precios, el IGV o los datos del cliente
 * cambian después, el CPE que se emite sigue siendo el que se le prometió al
 * cliente. El modelo lo impone: mutar `payload` de una fila persistida lanza.
 *
 * No confundir con `Comprobante` (tabla `comprobantes`), que es la ruta Greenter.
 *
 * @property array $payload
 * @property string $estado
 * @property int $intentos
 * @property int $max_intentos
 */
class ComprobanteCola extends Model
{
    use HasFactory;

    protected $table = 'comprobantes_cola';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';

    /** Encolado, aún no tomado por el worker. */
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    /** Tomado por un worker (lock optimista). Legacy: `enviando`. */
    public const ESTADO_ENVIADO = 'ENVIADO';
    /** SUNAT lo aceptó. Terminal. */
    public const ESTADO_ACEPTADO = 'ACEPTADO';
    /** SUNAT lo rechazó, o error no reintentable. Terminal. */
    public const ESTADO_RECHAZADO = 'RECHAZADO';
    /** Anulado por nota de crédito / baja. Terminal. */
    public const ESTADO_ANULADO = 'ANULADO';
    /** Falló de forma reintentable; espera a `proximo_intento_at`. */
    public const ESTADO_ERROR = 'ERROR';

    public const TIPO_BOLETA = 'BOLETA';
    public const TIPO_FACTURA = 'FACTURA';
    public const TIPO_NOTA_CREDITO = 'NOTA_CREDITO';

    /** Techo del backoff exponencial, en minutos. Igual que el legacy. */
    public const BACKOFF_MAX_MINUTOS = 60;

    protected $fillable = [
        'venta_id', 'reporte_id', 'ticket_id',
        'tienda_id', 'agente_id', 'facturacion_config_id', 'comprobante_afectado_id',
        'clave_idempotencia', 'tipo_comprobante',
        'tipo_doc_cliente', 'num_doc_cliente', 'razon_social',
        'direccion_cliente', 'email_cliente', 'moneda', 'total',
        'payload', 'estado', 'intentos', 'max_intentos', 'proximo_intento_at',
        'ultimo_error', 'serie', 'correlativo', 'api_doc_id',
        'sunat_ticket', 'cdr_estado', 'cdr_hash', 'xml_path', 'cdr_path', 'pdf_path',
        'anulado_por_usuario_id', 'anulado_en', 'anulado_motivo',
    ];

    /** Los mismos defaults que la tabla, para que la fila recién creada no los lea como null. */
    protected $attributes = [
        'estado'       => self::ESTADO_PENDIENTE,
        'intentos'     => 0,
        'max_intentos' => 8,
    ];

    /** El listado de la cola (gerencia) necesita el número armado sin un accessor manual en el controller. */
    protected $appends = ['numero_completo'];

    protected $casts = [
        'venta_id'                => 'integer',
        'reporte_id'              => 'integer',
        'ticket_id'               => 'integer',
        'agente_id'               => 'integer',
        'facturacion_config_id'   => 'integer',
        'comprobante_afectado_id' => 'integer',
        'anulado_por_usuario_id'  => 'integer',
        'anulado_en'              => 'datetime',
        'payload'                 => 'array',
        'total'                   => 'decimal:2',
        'intentos'                => 'integer',
        'max_intentos'            => 'integer',
        'correlativo'             => 'integer',
        'api_doc_id'              => 'integer',
        'proximo_intento_at'      => 'datetime',
        'creado_en'               => 'datetime',
        'actualizado_en'          => 'datetime',
    ];

    /** El snapshot fiscal es inmutable una vez encolado. */
    protected static function booted(): void
    {
        static::saving(function (self $cola) {
            if ($cola->exists && $cola->isDirty('payload')) {
                throw new \LogicException(
                    "El snapshot fiscal del comprobante {$cola->getKey()} es inmutable: "
                    . 'no se recalcula el payload en los reintentos.'
                );
            }
        });
    }

    // ── Encolado ─────────────────────────────────────────────────────────────

    /**
     * Encola un comprobante de forma idempotente.
     *
     * La idempotencia la sostiene el índice único sobre `clave_idempotencia`. Si no
     * se pasa una, se deriva del origen + tipo, de modo que encolar dos veces la
     * misma venta con el mismo tipo devuelve la MISMA fila, con su payload original
     * intacto. Una fila sin origen (ni venta, ni reporte, ni ticket) no tiene clave
     * y siempre inserta: los NULL no colisionan en un UNIQUE.
     */
    public static function encolar(array $atributos): static
    {
        $atributos['clave_idempotencia'] ??= static::claveIdempotencia(
            $atributos['tipo_comprobante'] ?? '',
            $atributos
        );

        $clave = $atributos['clave_idempotencia'];

        if ($clave === null) {
            return static::query()->create($atributos);
        }

        // `firstOrCreate` no es atómico entre procesos: dos workers pueden pasar el
        // `first()` a la vez. El UNIQUE decide, y el perdedor relee la fila del otro.
        try {
            return DB::transaction(
                fn () => static::query()->firstOrCreate(['clave_idempotencia' => $clave], $atributos)
            );
        } catch (UniqueConstraintViolationException) {
            return static::query()->where('clave_idempotencia', $clave)->firstOrFail();
        }
    }

    /**
     * Clave derivada del origen. Devuelve null si la fila no tiene ningún origen
     * identificable: sin origen no hay nada que deduplicar.
     */
    public static function claveIdempotencia(string $tipoComprobante, array $atributos): ?string
    {
        foreach (['venta_id', 'reporte_id', 'ticket_id'] as $origen) {
            if (!empty($atributos[$origen])) {
                return sprintf('%s:%d:%s', str_replace('_id', '', $origen), $atributos[$origen], $tipoComprobante);
            }
        }

        return null;
    }

    // ── Correlativo ──────────────────────────────────────────────────────────

    /**
     * Reserva serie y correlativo para este comprobante, si aún no los tiene.
     *
     * Idempotente a propósito: un reintento NO consume un número nuevo. El número
     * se reserva una vez y sobrevive a los fallos de la API.
     */
    public function asignarCorrelativo(FacturacionConfig $config): static
    {
        if ($this->correlativo !== null) {
            return $this;
        }

        $serie = $this->serieSegunTipo($config);

        $this->forceFill([
            'facturacion_config_id' => $config->getKey(),
            'serie'                 => $serie,
            'correlativo'           => ComprobanteCorrelativo::siguiente($config, $serie),
        ])->save();

        return $this;
    }

    /** La serie SIEMPRE viene del emisor, nunca del payload encolado. */
    public function serieSegunTipo(FacturacionConfig $config): string
    {
        return match ($this->tipo_comprobante) {
            self::TIPO_FACTURA      => $config->serie_factura,
            self::TIPO_NOTA_CREDITO => $config->serie_nota_credito,
            default                 => $config->serie_boleta,
        };
    }

    /** `B001-00000123`, o null si aún no se reservó número. */
    public function getNumeroCompletoAttribute(): ?string
    {
        if ($this->serie === null || $this->correlativo === null) {
            return null;
        }

        return $this->serie . '-' . str_pad((string) $this->correlativo, 8, '0', STR_PAD_LEFT);
    }

    // ── Máquina de estados ───────────────────────────────────────────────────

    /**
     * Lock optimista del worker: pasa PENDIENTE|ERROR → ENVIADO en un solo UPDATE
     * condicional. `false` = otro worker ya la tomó; hay que saltarla.
     */
    public function reclamar(): bool
    {
        $tomada = static::query()
            ->whereKey($this->getKey())
            ->whereIn('estado', [self::ESTADO_PENDIENTE, self::ESTADO_ERROR])
            ->update(['estado' => self::ESTADO_ENVIADO, 'actualizado_en' => now()]);

        if ($tomada === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * Falla reintentable: suma un intento y reprograma con backoff exponencial
     * (2^intentos minutos, techo 60), igual que el cron legacy.
     */
    public function registrarError(string $mensaje, bool $reintentable = true): static
    {
        if (!$reintentable) {
            return $this->marcarRechazado($mensaje);
        }

        $intentos = $this->intentos + 1;

        $this->forceFill([
            'estado'             => self::ESTADO_ERROR,
            'intentos'           => $intentos,
            'ultimo_error'       => $mensaje,
            'proximo_intento_at' => now()->addMinutes(self::backoffMinutos($intentos)),
        ])->save();

        return $this;
    }

    public static function backoffMinutos(int $intentos): int
    {
        return (int) min(self::BACKOFF_MAX_MINUTOS, 2 ** $intentos);
    }

    /**
     * El número fiscal (`serie`/`correlativo`) lo asigna la API externa y llega en
     * `$resultado`: es ella la que lleva la numeración ante SUNAT. Ver
     * `ComprobanteCorrelativo` para la secuencia local, que hoy no alimenta la API.
     */
    public function marcarAceptado(array $resultado = []): static
    {
        $this->forceFill(array_intersect_key($resultado, array_flip([
            'serie', 'correlativo',
            'api_doc_id', 'sunat_ticket', 'cdr_estado', 'cdr_hash', 'xml_path', 'cdr_path', 'pdf_path',
        ])) + [
            'estado'             => self::ESTADO_ACEPTADO,
            'ultimo_error'       => null,
            'proximo_intento_at' => null,
        ])->save();

        return $this;
    }

    public function marcarRechazado(string $mensaje): static
    {
        $this->forceFill([
            'estado'             => self::ESTADO_RECHAZADO,
            'ultimo_error'       => $mensaje,
            'proximo_intento_at' => null,
        ])->save();

        return $this;
    }

    /**
     * `$mensaje` es lo que devolvió la API de baja (p.ej. "BAJA ticket=123"), igual
     * que el legacy lo guardaba en `ultimo_error`. `$motivo` es el motivo que
     * escribió el admin al pedir la anulación: es la auditoría (quién/cuándo la
     * lleva `$usuarioId` + `now()`, por qué la lleva `$motivo`).
     */
    public function marcarAnulado(?string $mensaje = null, ?int $usuarioId = null, ?string $motivo = null): static
    {
        $this->forceFill([
            'estado'                 => self::ESTADO_ANULADO,
            'ultimo_error'           => $mensaje,
            'proximo_intento_at'     => null,
            'anulado_por_usuario_id' => $usuarioId,
            'anulado_en'             => now(),
            'anulado_motivo'         => $motivo,
        ])->save();

        return $this;
    }

    public function estaAceptado(): bool
    {
        return $this->estado === self::ESTADO_ACEPTADO;
    }

    public function agotoIntentos(): bool
    {
        return $this->intentos >= $this->max_intentos;
    }

    /** Filas que el worker debe tomar ahora. Misma condición que el cron legacy. */
    public function scopePendientes(Builder $query): Builder
    {
        return $query
            ->whereIn('estado', [self::ESTADO_PENDIENTE, self::ESTADO_ERROR])
            ->whereColumn('intentos', '<', 'max_intentos')
            ->where(fn (Builder $q) => $q->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', now()))
            ->orderBy('creado_en');
    }

    // ── Relaciones ───────────────────────────────────────────────────────────

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(Reporte::class, 'reporte_id');
    }

    public function facturacionConfig(): BelongsTo
    {
        return $this->belongsTo(FacturacionConfig::class, 'facturacion_config_id');
    }

    /** El comprobante que esta NOTA_CREDITO afecta. */
    public function comprobanteAfectado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'comprobante_afectado_id');
    }

    /** Config del emisor: la ya fijada al emitir, o la que resuelve la tienda. */
    public function resolverConfig(): ?FacturacionConfig
    {
        return $this->facturacionConfig ?? FacturacionConfig::paraTienda($this->tienda_id);
    }
}
