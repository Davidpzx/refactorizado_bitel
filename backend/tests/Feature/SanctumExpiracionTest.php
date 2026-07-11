<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * SEC-07: los tokens Sanctum deben tener expiración configurada (no null) para que un
 * token filtrado no sea válido indefinidamente. Default: 14 días (en minutos).
 */
class SanctumExpiracionTest extends TestCase
{
    public function test_expiration_de_sanctum_no_es_null(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull($expiration);
        $this->assertIsInt($expiration);
        $this->assertGreaterThan(0, $expiration);
        $this->assertSame(60 * 24 * 14, $expiration);
    }
}
