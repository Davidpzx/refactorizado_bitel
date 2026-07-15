<?php

namespace Database\Seeders;

use App\Models\WhatsAppBotRegla;
use Illuminate\Database\Seeder;

class WhatsAppBotReglasSeeder extends Seeder
{
    public function run(): void
    {
        if (WhatsAppBotRegla::query()->exists()) {
            return; // idempotente
        }

        $planes = WhatsAppBotRegla::create([
            'nombre' => 'Planes', 'tipo' => 'texto', 'prioridad' => 10,
            'usa_promocion_dinamica' => true,
            'palabras_clave' => ['plan', 'planes', 'promocion', 'precio', 'cuanto'],
            'respuesta' => "Estos son nuestros planes vigentes 📱:\n\n• Plan S/29.90 — 20GB + llamadas ilimitadas\n• Plan S/39.90 — 40GB + llamadas ilimitadas\n• Plan S/49.90 — GB ilimitados",
        ]);
        $equipos = WhatsAppBotRegla::create([
            'nombre' => 'Equipos', 'tipo' => 'equipos', 'prioridad' => 10,
            'palabras_clave' => ['equipo', 'equipos', 'celular', 'telefono', 'stock'],
            'respuesta' => 'Por ahora no tenemos equipos en stock, un asesor te confirma disponibilidad.',
        ]);
        WhatsAppBotRegla::create([
            'nombre' => 'Horario y ubicacion', 'tipo' => 'texto', 'prioridad' => 10,
            'palabras_clave' => ['horario', 'direccion', 'donde', 'ubicacion'],
            'respuesta' => 'Nuestro horario de atención es de lunes a sábado, 9:00am a 8:00pm 🕗. Escríbenos y te pasamos la dirección de la tienda más cercana.',
        ]);
        WhatsAppBotRegla::create([
            'nombre' => 'Bienvenida', 'tipo' => 'menu', 'es_bienvenida' => true, 'prioridad' => 100,
            'menu_titulo' => '¡Hola! 👋 Gracias por escribir. ¿En qué te ayudamos?',
            'opciones' => [
                ['id' => 'op_planes', 'texto' => 'Planes y promociones', 'regla_id' => $planes->id],
                ['id' => 'op_equipos', 'texto' => 'Equipos disponibles', 'regla_id' => $equipos->id],
                ['id' => 'op_asesor', 'texto' => 'Hablar con un asesor', 'regla_id' => null],
            ],
        ]);
    }
}
