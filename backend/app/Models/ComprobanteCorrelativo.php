<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Secuencia de correlativos, una fila por (emisor, serie).
 *
 * Existe para que el número del CPE nunca se derive de `MAX(correlativo)+1` sobre
 * `comprobantes_cola`: bajo concurrencia dos procesos leerían el mismo máximo y
 * emitirían el mismo número. Aquí el siguiente número sale de una fila única que se
 * bloquea con `SELECT ... FOR UPDATE` dentro de una transacción.
 *
 * @property int $facturacion_config_id
 * @property string $serie
 * @property int $ultimo_correlativo
 */
class ComprobanteCorrelativo extends Model
{
    protected $table = 'comprobantes_correlativos';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['facturacion_config_id', 'serie', 'ultimo_correlativo'];

    protected $casts = [
        'facturacion_config_id' => 'integer',
        'ultimo_correlativo'    => 'integer',
        'creado_en'             => 'datetime',
        'actualizado_en'        => 'datetime',
    ];

    /**
     * Reserva y devuelve el siguiente correlativo de (emisor, serie).
     *
     * Se ejecuta siempre dentro de una transacción: si ya hay una abierta, Laravel
     * anida con savepoint y el lock lo sostiene la transacción externa. El
     * `lockForUpdate()` serializa a los procesos concurrentes sobre la fila de
     * secuencia — el segundo espera al COMMIT del primero y lee el valor ya
     * incrementado.
     *
     * El número reservado se consume aunque la emisión falle después. SUNAT tolera
     * huecos en la numeración; un número repetido, no.
     */
    public static function siguiente(FacturacionConfig $config, string $serie): int
    {
        $serie = trim($serie);

        if ($serie === '') {
            throw new \InvalidArgumentException('La serie no puede estar vacía.');
        }

        return DB::transaction(function () use ($config, $serie) {
            $llave = ['facturacion_config_id' => $config->getKey(), 'serie' => $serie];

            // Crea la fila si es la primera vez. `insertOrIgnore` absorbe la carrera
            // entre dos procesos que la crean a la vez: uno gana, el otro no falla.
            static::query()->insertOrIgnore($llave + [
                'ultimo_correlativo' => 0,
                'creado_en'          => now(),
                'actualizado_en'     => now(),
            ]);

            $secuencia = static::query()->where($llave)->lockForUpdate()->first();

            $secuencia->ultimo_correlativo++;
            $secuencia->save();

            return $secuencia->ultimo_correlativo;
        }, 3);
    }

    public function facturacionConfig(): BelongsTo
    {
        return $this->belongsTo(FacturacionConfig::class, 'facturacion_config_id');
    }
}
