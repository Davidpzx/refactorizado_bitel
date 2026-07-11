<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_public_api_response_includes_security_headers_without_hsts_over_http(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_public_api_response_includes_hsts_when_forwarded_as_https(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
    }
}
