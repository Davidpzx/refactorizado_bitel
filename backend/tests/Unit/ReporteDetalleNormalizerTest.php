<?php

namespace Tests\Unit;

use App\Services\ReporteDetalleNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la regla heredada del legacy: el JSON `detalle` de reporte_categorias
 * puede ser objeto único `{}` o lista `[{}]`, y siempre debe normalizarse al leer
 * y preservar su forma al guardar.
 */
class ReporteDetalleNormalizerTest extends TestCase
{
    public function test_objeto_unico_se_envuelve_como_lista_de_uno(): void
    {
        $json = json_encode(['producto' => 'A55', 'identificador' => 'IMEI123', 'ganancia' => 100]);

        $out = ReporteDetalleNormalizer::decodificar($json);

        $this->assertTrue($out['valido']);
        $this->assertFalse($out['eraLista']);
        $this->assertCount(1, $out['items']);
        $this->assertSame('A55', $out['items'][0]['producto']);
    }

    public function test_array_multiple_se_conserva_como_lista(): void
    {
        $json = json_encode([
            ['producto' => 'A55', 'ganancia' => 100],
            ['producto' => 'A35', 'ganancia' => 80],
        ]);

        $out = ReporteDetalleNormalizer::decodificar($json);

        $this->assertTrue($out['valido']);
        $this->assertTrue($out['eraLista']);
        $this->assertCount(2, $out['items']);
        $this->assertSame('A35', $out['items'][1]['producto']);
    }

    public function test_json_invalido_degrada_a_vacio_sin_lanzar(): void
    {
        $out = ReporteDetalleNormalizer::decodificar('{no es json valido');

        $this->assertFalse($out['valido']);
        $this->assertSame([], $out['items']);
        $this->assertSame([], ReporteDetalleNormalizer::items('}}}garbage'));
        $this->assertSame([], ReporteDetalleNormalizer::items(null));
        $this->assertSame([], ReporteDetalleNormalizer::items(''));
    }

    public function test_campos_faltantes_no_rompen_lectura(): void
    {
        // Un ítem con menos llaves de las esperadas: se lee tal cual, sin error.
        $out = ReporteDetalleNormalizer::decodificar(json_encode(['producto' => 'A55']));

        $item = $out['items'][0];
        $this->assertArrayNotHasKey('ganancia', $item);
        $this->assertSame(
            0,
            ReporteDetalleNormalizer::campo($item, ['ganancia', 'precio_total'], 0)
        );
        $this->assertSame('A55', ReporteDetalleNormalizer::campo($item, ['producto'], 'N/A'));
    }

    public function test_items_no_array_se_descartan(): void
    {
        // Root lista con basura mezclada (string suelto) → solo sobreviven objetos.
        $json = json_encode([['producto' => 'A55'], 'basura', 42]);

        $items = ReporteDetalleNormalizer::items($json);

        $this->assertCount(1, $items);
        $this->assertSame('A55', $items[0]['producto']);
    }

    public function test_otros_flujo_conserva_monto_motivo_y_comision_agente(): void
    {
        $json = json_encode([
            'monto' => 25.50,
            'motivo' => 'Venta de accesorio suelto',
            'comision_agente' => 3.75,
        ]);

        $out = ReporteDetalleNormalizer::decodificar($json);

        $this->assertFalse($out['eraLista']);
        $this->assertCount(1, $out['items']);
        $flujo = $out['items'][0];
        $this->assertSame(25.50, $flujo['monto']);
        $this->assertSame('Venta de accesorio suelto', $flujo['motivo']);
        $this->assertSame(3.75, $flujo['comision_agente']);
    }

    public function test_preserva_forma_objeto_al_reencodar(): void
    {
        $raw = json_encode(['producto' => 'A55', 'ganancia' => 100]);
        $out = ReporteDetalleNormalizer::decodificar($raw);

        $items = $out['items'];
        $items[0]['ganancia'] = 999;

        $guardado = ReporteDetalleNormalizer::encodearPreservandoForma($items, $out['eraLista']);
        $decoded = json_decode($guardado, true);

        // Debe volver a guardarse como OBJETO (no lista) porque el root era objeto.
        $this->assertArrayHasKey('producto', $decoded);
        $this->assertArrayNotHasKey(0, $decoded);
        $this->assertSame(999, $decoded['ganancia']);
    }

    public function test_preserva_forma_lista_al_reencodar(): void
    {
        $raw = json_encode([
            ['producto' => 'A55', 'ganancia' => 100],
            ['producto' => 'A35', 'ganancia' => 80],
        ]);
        $out = ReporteDetalleNormalizer::decodificar($raw);

        $guardado = ReporteDetalleNormalizer::encodearPreservandoForma($out['items'], $out['eraLista']);
        $decoded = json_decode($guardado, true);

        // Debe seguir siendo una LISTA de 2.
        $this->assertArrayHasKey(0, $decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('A35', $decoded[1]['producto']);
    }

    public function test_acepta_array_ya_decodificado(): void
    {
        $out = ReporteDetalleNormalizer::decodificar(['producto' => 'A55']);

        $this->assertTrue($out['valido']);
        $this->assertFalse($out['eraLista']);
        $this->assertSame('A55', $out['items'][0]['producto']);
    }
}
