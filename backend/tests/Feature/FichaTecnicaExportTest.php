<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FichaTecnicaExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_ficha_tecnica_xlsx(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Juan Perez',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO', 'sueldo_base' => 1200,
        ]);

        if (Schema::hasTable('postulantes_temp')) {
            DB::table('postulantes_temp')->insert([
                'dni' => '12345678', 'nombres' => 'Juan', 'apellidos' => 'Perez',
                'telefono' => '999888777', 'correo' => 'juan@x.com', 'estado' => 'APROBADO',
                'carga_familiar' => json_encode([['nombre' => 'Hijo', 'parentesco' => 'hijo', 'edad' => 5]]),
                'formacion_academica' => json_encode([]),
                'experiencia_laboral' => json_encode([]),
                'contactos_emergencia' => json_encode([['nombre' => 'Mama', 'parentesco' => 'madre', 'telefono' => '111']]),
            ]);
        }

        $resp = $this->actingAs($admin, 'sanctum')->get('/api/v1/agentes/exportar-ficha');

        $resp->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $resp->headers->get('content-type'));
    }
}
