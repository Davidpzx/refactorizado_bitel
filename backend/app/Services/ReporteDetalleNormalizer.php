<?php

namespace App\Services;

/**
 * Normalizador único del JSON `detalle` de `reporte_categorias` (y del blob
 * `otros_flujo`, que se guarda en la misma columna con `tipo = 'otros_flujo'`).
 *
 * ── Regla heredada del legacy (sistema-rolando-salas) ──────────────────────────
 * La columna `detalle` puede contener **un objeto único** `{...}` (venta
 * individual) o **una lista de objetos** `[{...}, ...]` (varios ítems agrupados).
 * El legacy la normalizaba en CADA punto de lectura con la copia frágil:
 *
 *     $detalle = json_decode($fila['detalle'], true);
 *     $es_array_raiz = isset($detalle[0]);
 *     $items = $es_array_raiz ? $detalle : [$detalle];
 *     ...
 *     $nuevo = $es_array_raiz ? $items : $items[0];   // preservar la forma al guardar
 *
 * (ver `reportes/ver_reporte.php`, `gerencia/recalcular_ganancias.php`,
 * `estadisticas_ventas.php`, `exportar_excel.php`, `fijar_precio.php`, …).
 *
 * Este service centraliza esa regla: **decodificar → si el root no es lista,
 * envolver como lista de 1 → operar siempre como lista → al guardar preservar la
 * forma original solo cuando la compatibilidad lo exige.** JSON inválido, vacío o
 * no-array degrada a `[]` sin lanzar (paridad con el `continue` del legacy).
 *
 * ── Guía para features NUEVAS ─────────────────────────────────────────────────
 * NO extiendas el uso del JSON `detalle`. Para lógica nueva prefiere SIEMPRE las
 * tablas normalizadas (`ventas`, `venta_lineas`, `venta_equipos`, …), que ya
 * contienen los mismos datos con tipos y llaves. Este normalizador existe solo
 * para los puntos que aún deben leer/escribir el blob heredado por compatibilidad.
 */
final class ReporteDetalleNormalizer
{
    /**
     * Decodifica y normaliza el blob a una lista de ítems, conservando además si
     * el root venía ya como lista (para poder re-guardar con la misma forma).
     *
     * @param  mixed  $raw  string JSON, array ya decodificado, o null.
     * @return array{valido: bool, eraLista: bool, items: array<int, array<string, mixed>>}
     */
    public static function decodificar(mixed $raw): array
    {
        $decoded = self::aArray($raw);

        if (! is_array($decoded)) {
            return ['valido' => false, 'eraLista' => false, 'items' => []];
        }

        // `isset($d[0])` = root ya es una lista secuencial (paridad legacy exacta).
        $eraLista = isset($decoded[0]);
        $items = $eraLista ? array_values($decoded) : [$decoded];

        // Validar que cada ítem sea un objeto asociativo; descartar basura.
        $items = array_values(array_filter(
            $items,
            static fn ($item): bool => is_array($item)
        ));

        return [
            'valido' => true,
            'eraLista' => $eraLista,
            'items' => $items,
        ];
    }

    /**
     * Atajo: solo la lista normalizada de ítems (cada uno un array asociativo).
     * Es lo que necesitan los puntos que solo LEEN el detalle.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(mixed $raw): array
    {
        return self::decodificar($raw)['items'];
    }

    /**
     * Re-encoda la lista de ítems **preservando la forma original** del blob:
     * si venía como objeto único (`$eraLista === false`) y queda exactamente un
     * ítem, se guarda como objeto `{...}`; en cualquier otro caso se guarda como
     * lista `[{...}]`. Espejo de `$es_array_raiz ? $items : $items[0]` del legacy.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function encodearPreservandoForma(array $items, bool $eraLista): string
    {
        $items = array_values($items);

        $payload = (! $eraLista && count($items) === 1)
            ? $items[0]
            : $items;

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Lee un campo de un ítem probando varias llaves en orden (paridad con los
     * encadenados `?? ?? ??` del legacy), devolviendo el primero presente.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $claves
     */
    public static function campo(array $item, array $claves, mixed $default = null): mixed
    {
        foreach ($claves as $clave) {
            if (array_key_exists($clave, $item) && $item[$clave] !== null) {
                return $item[$clave];
            }
        }

        return $default;
    }

    /**
     * Decodifica cualquier entrada aceptada a un array PHP (o `null` si no aplica).
     */
    private static function aArray(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
