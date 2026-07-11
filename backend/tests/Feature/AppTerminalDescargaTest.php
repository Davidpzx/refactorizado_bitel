<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * APP-09a — canal de distribución del APK de la app de asistencia.
 */
class AppTerminalDescargaTest extends TestCase
{
    use RefreshDatabase;

    private string $apkDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apkDir = storage_path('app/app-terminal');
        // Partir siempre de un servidor sin APK publicado.
        File::deleteDirectory($this->apkDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->apkDir);
        parent::tearDown();
    }

    public function test_version_sin_apk_responde_no_disponible(): void
    {
        $this->getJson('/api/v1/app-terminal/version')
            ->assertOk()
            ->assertJson(['version' => null, 'disponible' => false]);
    }

    public function test_descargar_sin_apk_es_404(): void
    {
        $this->getJson('/api/v1/app-terminal/descargar')
            ->assertStatus(404);
    }

    public function test_subir_como_admin_funciona_y_luego_version_y_descargar_responden_bien(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $apk = UploadedFile::fake()->create('asistencia.apk', 500, 'application/vnd.android.package-archive');

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/app-terminal/subir', ['apk' => $apk, 'version' => '1.2.3'])
            ->assertOk()
            ->assertJsonPath('version', '1.2.3');

        $this->getJson('/api/v1/app-terminal/version')
            ->assertOk()
            ->assertJsonPath('version', '1.2.3')
            ->assertJsonPath('disponible', true)
            ->assertJsonPath('url_descarga', url('/api/v1/app-terminal/descargar'));

        $resp = $this->get('/api/v1/app-terminal/descargar');
        $resp->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $resp->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
    }

    public function test_subir_como_no_admin_es_403(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $apk = UploadedFile::fake()->create('asistencia.apk', 500, 'application/vnd.android.package-archive');

        $this->actingAs($vendedor, 'sanctum')
            ->post('/api/v1/app-terminal/subir', ['apk' => $apk, 'version' => '1.2.3'])
            ->assertStatus(403);
    }
}
