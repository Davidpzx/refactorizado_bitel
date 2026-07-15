<?php

namespace Tests\Unit;

use App\Models\WhatsAppChat;
use Tests\TestCase;

class WhatsAppChatNormalizarJidTest extends TestCase
{
    public function test_numero_local_de_9_digitos_recibe_prefijo_51(): void
    {
        $this->assertSame('51917930560@s.whatsapp.net', WhatsAppChat::normalizarJid('917930560'));
    }

    public function test_numero_ya_con_prefijo_51_no_se_duplica(): void
    {
        $this->assertSame('51917930560@s.whatsapp.net', WhatsAppChat::normalizarJid('51917930560'));
    }

    public function test_ignora_caracteres_no_numericos(): void
    {
        $this->assertSame('51917930560@s.whatsapp.net', WhatsAppChat::normalizarJid('+51 917-930-560'));
    }
}
