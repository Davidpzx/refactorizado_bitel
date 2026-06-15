<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanillaOnlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_comision_online_recargas_mas_bcp(): void
    {
        // Tablas legacy no migradas: se crean para el test (en el VPS ya existen).
        if (! Schema::hasTable('config_comisiones')) {
            Schema::create('config_comisiones', function (Blueprint $t) {
                $t->id();
                $t->string('tipo', 50);
                $t->decimal('monto', 12, 2)->default(0);
            });
        }
        if (! Schema::hasTable('reportes_bcp')) {
            Schema::create('reportes_bcp', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agente_id');
                $t->date('fecha');
                $t->integer('cantidad_operaciones')->default(0);
            });
        }

        DB::table('config_comisiones')->insert([
            ['tipo' => 'ganancia_recargas', 'monto' => 5],  // 5 %
            ['tipo' => 'COMISION_BCP', 'monto' => 2],       // S/ 2 por operación
        ]);

        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Agente Uno',
            'tienda_base' => 'PUNDA50', 'sueldo_base' => 1200, 'estado' => 'ACTIVO',
        ]);

        DB::table('reportes')->insert([
            'agente_id' => 1, 'tienda_id' => 'PUNDA50', 'fecha' => '2026-06-10',
            'recarga_bipay' => 1000, 'estado' => 'borrador',
        ]);
        DB::table('reportes_bcp')->insert([
            'agente_id' => 1, 'fecha' => '2026-06-10', 'cantidad_operaciones' => 100,
        ]);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/planilla/2026-06')->assertOk();
        $fila = collect($resp->json('agentes'))->firstWhere('agente_id', 1);

        // 1000 × 0.05  +  100 × 2  =  50 + 200 = 250
        $this->assertNotNull($fila);
        $this->assertSame(250.0, (float) $fila['comision_online']);
    }
}
