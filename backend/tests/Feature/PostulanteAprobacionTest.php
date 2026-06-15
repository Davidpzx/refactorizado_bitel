<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostulanteAprobacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $columns = [
            'apellidos' => fn (Blueprint $table) => $table->string('apellidos', 150)->nullable(),
            'telefono' => fn (Blueprint $table) => $table->string('telefono', 15)->nullable(),
            'correo' => fn (Blueprint $table) => $table->string('correo', 120)->nullable(),
            'fecha_nacimiento' => fn (Blueprint $table) => $table->date('fecha_nacimiento')->nullable(),
            'direccion' => fn (Blueprint $table) => $table->text('direccion')->nullable(),
            'lugar_nacimiento' => fn (Blueprint $table) => $table->string('lugar_nacimiento', 255)->nullable(),
            'formacion_academica' => fn (Blueprint $table) => $table->longText('formacion_academica')->nullable(),
            'carga_familiar' => fn (Blueprint $table) => $table->text('carga_familiar')->nullable(),
            'experiencia_laboral' => fn (Blueprint $table) => $table->text('experiencia_laboral')->nullable(),
            'sistema_pension' => fn (Blueprint $table) => $table->string('sistema_pension', 10)->nullable(),
            'nombre_afp' => fn (Blueprint $table) => $table->string('nombre_afp', 50)->nullable(),
            'numero_cuspp' => fn (Blueprint $table) => $table->string('numero_cuspp', 20)->nullable(),
            'grupo_sanguineo' => fn (Blueprint $table) => $table->string('grupo_sanguineo', 5)->nullable(),
            'alergias' => fn (Blueprint $table) => $table->text('alergias')->nullable(),
            'antecedentes_penales' => fn (Blueprint $table) => $table->boolean('antecedentes_penales')->default(false),
            'antecedentes_policial' => fn (Blueprint $table) => $table->boolean('antecedentes_policial')->default(false),
            'antecedentes_judicial' => fn (Blueprint $table) => $table->boolean('antecedentes_judicial')->default(false),
            'contactos_emergencia' => fn (Blueprint $table) => $table->text('contactos_emergencia')->nullable(),
            'fecha_ingreso' => fn (Blueprint $table) => $table->date('fecha_ingreso')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('agentes', $column)) {
                Schema::table('agentes', $definition);
            }
        }
    }

    public function test_admin_aprueba_postulante_copiando_datos_y_pin_por_defecto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $postulanteId = $this->crearPostulante();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Postulante aprobado y agente creado.');

        $agente = DB::table('agentes')->where('dni', '12345678')->first();

        $this->assertNotNull($agente);
        $this->assertSame('JUAN CARLOS PEREZ LOPEZ', $agente->nombres);
        $this->assertSame('PEREZ LOPEZ', $agente->apellidos);
        $this->assertSame('PUNDA50', $agente->tienda_base);
        $this->assertSame('AFP', $agente->sistema_pension);
        $this->assertSame('[{"nombre":"HIJO"}]', $agente->carga_familiar);
        $this->assertTrue(Hash::check('5678', $agente->pin_seguridad));

        $this->assertDatabaseHas('postulantes_temp', [
            'id' => $postulanteId,
            'estado' => 'APROBADO',
            'revisado_por' => $admin->id,
        ]);
    }

    public function test_aprobacion_permite_pin_y_datos_laborales_personalizados(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $postulanteId = $this->crearPostulante();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar", [
                'pin_seguridad' => '2468',
                'tienda_base' => 'PUNDA51',
                'sueldo_base' => 1300,
                'fecha_ingreso' => '2026-06-15',
            ])
            ->assertCreated();

        $agente = DB::table('agentes')->where('dni', '12345678')->first();

        $this->assertSame('PUNDA51', $agente->tienda_base);
        $this->assertSame(1300.0, (float) $agente->sueldo_base);
        $this->assertSame('2026-06-15', $agente->fecha_ingreso);
        $this->assertTrue(Hash::check('2468', $agente->pin_seguridad));
    }

    public function test_no_admin_no_puede_aprobar_postulante(): void
    {
        $vendedor = Usuario::factory()->vendedor()->create();
        $postulanteId = $this->crearPostulante();

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar")
            ->assertForbidden();

        $this->assertDatabaseMissing('agentes', ['dni' => '12345678']);
    }

    public function test_no_duplica_agente_al_repetir_aprobacion(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $postulanteId = $this->crearPostulante();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar")
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar")
            ->assertConflict()
            ->assertJsonPath('message', 'El postulante ya fue aprobado.');

        $this->assertSame(1, DB::table('agentes')->where('dni', '12345678')->count());
    }

    private function crearPostulante(): int
    {
        return DB::table('postulantes_temp')->insertGetId([
            'dni' => '12345678',
            'nombres' => 'JUAN CARLOS',
            'apellidos' => 'PEREZ LOPEZ',
            'telefono' => '999888777',
            'correo' => 'juan@example.com',
            'fecha_nacimiento' => '1995-03-20',
            'direccion' => 'AV. PRUEBA 123',
            'lugar_nacimiento' => 'LIMA',
            'tienda_postulada' => 'PUNDA50',
            'formacion_academica' => '[{"nivel":"TECNICO"}]',
            'carga_familiar' => '[{"nombre":"HIJO"}]',
            'experiencia_laboral' => '[{"empresa":"BITEL"}]',
            'sistema_pension' => 'AFP',
            'nombre_afp' => 'INTEGRA',
            'numero_cuspp' => 'CUSPP123',
            'grupo_sanguineo' => 'O+',
            'alergias' => 'NINGUNA',
            'antecedentes_penales' => false,
            'antecedentes_policial' => false,
            'antecedentes_judicial' => false,
            'contactos_emergencia' => '[{"nombre":"MARIA"}]',
            'estado' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
