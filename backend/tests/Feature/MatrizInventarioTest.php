<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ticket-026 (OLA DE FIXES 2): /inventario/matriz devolvía 500 en SQLite porque
 * la resolución de codigo_origen de chips usaba REGEXP/CAST(...AS UNSIGNED)
 * (sintaxis MySQL-only). Cubre los 3 casos de tienda_origen que antes se
 * resolvían en SQL: vacío, numérico (id de tienda) y código no numérico.
 */
class MatrizInventarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_matriz_resuelve_codigo_origen_de_chips_en_sqlite(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $t1 = DB::table('tiendas')->insertGetId([
            'codigo' => 'T1', 'nombre' => 'Tienda Uno', 'activo' => 1,
        ]);
        $t2 = DB::table('tiendas')->insertGetId([
            'codigo' => 'T2', 'nombre' => 'Tienda Dos', 'activo' => 1,
        ]);

        // tienda_origen vacío → resuelve a la propia tienda (T1)
        DB::table('inventario_chips')->insert([
            'tienda_id' => $t1, 'tienda_origen' => '', 'stock_actual' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // tienda_origen numérico (id de T1) → resuelve al código T1
        DB::table('inventario_chips')->insert([
            'tienda_id' => $t2, 'tienda_origen' => (string) $t1, 'stock_actual' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // tienda_origen no numérico → se usa tal cual
        DB::table('inventario_chips')->insert([
            'tienda_id' => $t2, 'tienda_origen' => 'EXTERNA', 'stock_actual' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/matriz')
            ->assertOk();

        $chips = collect($response->json('chips'))->keyBy('nombre');

        $this->assertSame(5, $chips['T1']['T1']);
        $this->assertSame(8, $chips['T1']['total']);

        $this->assertSame(3, $chips['T1']['T2']);
        $this->assertSame(2, $chips['EXTERNA']['T2']);
        $this->assertSame(2, $chips['EXTERNA']['total']);
    }
}
