<?php

namespace Tests\Unit;

use App\Services\WhatsApp\EvolutionProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionProviderTest extends TestCase
{
    public function test_enviar_texto_llama_al_endpoint_correcto_con_api_key(): void
    {
        Http::fake([
            '*/message/sendText/mi-instancia' => Http::response(['key' => ['id' => 'WA123']], 200),
        ]);

        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        $provider = new EvolutionProvider();
        $result = $provider->enviarTexto('mi-instancia', '51988888888@s.whatsapp.net', 'Hola');

        $this->assertSame('WA123', $result['key']['id']);
        Http::assertSent(function ($request) {
            return $request->hasHeader('apikey', 'secreto')
                && str_contains($request->url(), '/message/sendText/mi-instancia');
        });
    }

    public function test_estado_instancia_desconectada_si_evolution_responde_error(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response([], 500),
        ]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        $provider = new EvolutionProvider();
        $this->assertSame('desconectada', $provider->estadoInstancia('mi-instancia'));
    }
}