<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostulacionPublicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('agentes', 'foto_perfil')) {
            Schema::table('agentes', fn (Blueprint $table) => $table->longText('foto_perfil')->nullable());
        }
        if (! Schema::hasColumn('agentes', 'foto_dni')) {
            Schema::table('agentes', fn (Blueprint $table) => $table->longText('foto_dni')->nullable());
        }
    }

    public function test_postulacion_publica_guarda_foto_de_perfil_y_dni_en_base64(): void
    {
        $response = $this->postJson('/api/v1/postulaciones', [
            'dni' => '87654321',
            'nombres' => 'ANA',
            'apellidos' => 'TORRES',
            'foto_perfil' => UploadedFile::fake()->image('perfil.jpg', 200, 200),
            'foto_dni' => UploadedFile::fake()->image('dni.jpg', 200, 200),
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $postulante = DB::table('postulantes_temp')->where('dni', '87654321')->first();

        $this->assertNotNull($postulante->foto_perfil);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $postulante->foto_perfil);
        $this->assertNotNull($postulante->foto_dni);
    }

    public function test_postulacion_publica_acepta_pdf_para_foto_dni(): void
    {
        $response = $this->postJson('/api/v1/postulaciones', [
            'dni' => '87654322',
            'nombres' => 'LUIS',
            'apellidos' => 'RAMOS',
            'foto_dni' => UploadedFile::fake()->create('dni.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $postulante = DB::table('postulantes_temp')->where('dni', '87654322')->first();
        $this->assertStringStartsWith('data:application/pdf;base64,', $postulante->foto_dni);
    }

    public function test_postulacion_publica_rechaza_formato_invalido_de_foto_perfil(): void
    {
        $response = $this->postJson('/api/v1/postulaciones', [
            'dni' => '87654323',
            'nombres' => 'CARLA',
            'apellidos' => 'DIAZ',
            'foto_perfil' => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseMissing('postulantes_temp', ['dni' => '87654323']);
    }

    public function test_postulacion_publica_sin_documentos_no_falla(): void
    {
        $response = $this->postJson('/api/v1/postulaciones', [
            'dni' => '87654324',
            'nombres' => 'PEDRO',
            'apellidos' => 'VEGA',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('postulantes_temp', ['dni' => '87654324', 'foto_perfil' => null, 'foto_dni' => null]);
    }

    public function test_aprobar_copia_las_fotos_del_postulante_al_agente(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->postJson('/api/v1/postulaciones', [
            'dni' => '87654325',
            'nombres' => 'ROSA',
            'apellidos' => 'FLORES',
            'foto_perfil' => UploadedFile::fake()->image('perfil.jpg', 200, 200),
            'foto_dni' => UploadedFile::fake()->image('dni.jpg', 200, 200),
        ])->assertOk();

        $postulanteId = DB::table('postulantes_temp')->where('dni', '87654325')->value('id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulanteId}/aprobar")
            ->assertCreated();

        $agente = DB::table('agentes')->where('dni', '87654325')->first();
        $this->assertNotNull($agente->foto_perfil);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $agente->foto_perfil);
        $this->assertNotNull($agente->foto_dni);
    }
}
