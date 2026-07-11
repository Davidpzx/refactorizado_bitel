<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SEC-02: en producción, un QueryException no debe filtrar el mensaje interno del
 * driver (SQL, nombre de tablas/columnas) al cliente. bootstrap/app.php enmascara la
 * respuesta con un mensaje genérico y solo loguea el detalle real.
 */
class QueryExceptionProduccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_exception_en_produccion_no_expone_mensaje_interno_del_driver(): void
    {
        // Ruta de prueba bajo api/* que fuerza un QueryException real (tabla inexistente).
        Route::get('api/_test/query-exception-sec02', function () {
            DB::table('tabla_inexistente_sec02')->get();
        });

        // El handler de render solo enmascara cuando la app corre en producción.
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/_test/query-exception-sec02');

        $response->assertStatus(500);
        $response->assertExactJson([
            'error' => 'Error interno del servidor. Contacte al administrador.',
        ]);

        // No debe existir la clave de depuración temporal ni filtrarse el SQL/tabla.
        $this->assertArrayNotHasKey('debug_temporal', $response->json());
        $this->assertStringNotContainsString('tabla_inexistente_sec02', $response->getContent());
    }
}
