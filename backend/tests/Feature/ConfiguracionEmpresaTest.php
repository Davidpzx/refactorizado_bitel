<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Port de `gerencia/configuracion_empresa.php` (legacy): paridad funcional
 * del Perfil de Empresa — todos los campos deben persistir, el RUC/DNI del
 * gerente se validan, y el GET debe reflejar lo guardado.
 */
class ConfiguracionEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create();
    }

    private function datosCompletos(): array
    {
        return [
            'razon_social'        => 'MUNDO ANDROID TECHNOLOGY E.I.R.L',
            'nombre_comercial'    => 'Mundo Android',
            'ruc'                 => '20607842842',
            'gerente_general'     => 'JUAN ROLANDO SALAS RUIZ',
            'gerente_dni'         => '12345678',
            'sistema_nombre'      => 'SIS-BIPAY',
            'telefono_contacto'   => '987654321',
            'direccion_principal' => 'Av. Ejemplo 123, Lima',
            'correo_contacto'     => 'contacto@mundoandroid.pe',
        ];
    }

    public function test_el_put_persiste_todos_los_campos_del_legacy(): void
    {
        $datos = $this->datosCompletos();

        $respuesta = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos);

        $respuesta->assertOk()->assertJson(['message' => 'Configuración guardada.']);

        $fila = DB::table('configuracion_empresa')->where('id', 1)->first();
        foreach ($datos as $campo => $valor) {
            $this->assertSame($valor, $fila->{$campo}, "El campo {$campo} no persistió igual que el legacy.");
        }
    }

    public function test_el_get_refleja_lo_guardado_por_el_put(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $this->datosCompletos())
            ->assertOk();

        $respuesta = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/configuracion');

        $respuesta->assertOk()->assertJsonFragment([
            'razon_social' => 'MUNDO ANDROID TECHNOLOGY E.I.R.L',
            'ruc'          => '20607842842',
        ]);
    }

    public function test_rechaza_ruc_que_no_tiene_11_digitos_numericos(): void
    {
        $datos = array_merge($this->datosCompletos(), ['ruc' => '2060784284']); // 10 dígitos

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ruc');
    }

    public function test_rechaza_ruc_con_letras(): void
    {
        $datos = array_merge($this->datosCompletos(), ['ruc' => '2060784284A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ruc');
    }

    public function test_rechaza_dni_de_gerente_que_no_tiene_8_digitos(): void
    {
        $datos = array_merge($this->datosCompletos(), ['gerente_dni' => '123']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gerente_dni');
    }

    public function test_permite_dni_de_gerente_vacio(): void
    {
        $datos = array_merge($this->datosCompletos(), ['gerente_dni' => '']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos)
            ->assertOk();
    }

    public function test_requiere_razon_social(): void
    {
        $datos = array_merge($this->datosCompletos(), ['razon_social' => '']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/configuracion', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('razon_social');
    }

    public function test_un_usuario_de_tienda_no_puede_modificar_la_configuracion(): void
    {
        $this->actingAs(Usuario::factory()->vendedor('PUNDA50')->create(), 'sanctum')
            ->putJson('/api/v1/configuracion', $this->datosCompletos())
            ->assertForbidden();
    }

    public function test_subir_logo_lo_guarda_como_data_uri_base64(): void
    {
        $archivo = UploadedFile::fake()->image('logo.png', 100, 60);

        $respuesta = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/configuracion/logo', ['logo' => $archivo]);

        $respuesta->assertOk();
        $this->assertStringStartsWith('data:image/png;base64,', $respuesta->json('logo_base64'));

        $fila = DB::table('configuracion_empresa')->where('id', 1)->first();
        $this->assertStringStartsWith('data:image/png;base64,', $fila->logo_base64);
    }

    public function test_eliminar_logo_lo_deja_en_null(): void
    {
        DB::table('configuracion_empresa')->upsert(
            ['id' => 1, 'logo_base64' => 'data:image/png;base64,abc'],
            ['id'],
            ['logo_base64'],
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/configuracion/logo')
            ->assertOk();

        $fila = DB::table('configuracion_empresa')->where('id', 1)->first();
        $this->assertNull($fila->logo_base64);
    }
}
