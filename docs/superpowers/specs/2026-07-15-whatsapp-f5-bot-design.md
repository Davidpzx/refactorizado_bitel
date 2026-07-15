# F5 — Bot de auto-respuesta + detección de interesados (refactorizado_bitel) Design

## Contexto

F1-F4 construyeron el inbox interno de WhatsApp multi-cuenta sobre Evolution API (v2.3.7, contenedor `evolution-bitel-api` en el VPS): webhook (`WhatsAppWebhookController`), endpoints REST (`WhatsAppController`), provider intercambiable (`EvolutionProvider`), frontend React (`CrmWhatsAppTab` + componentes), y vínculo con el Pipeline (F4). El backend ya usa Redis (`QUEUE_CONNECTION=redis`).

F5 agrega:
1. **Bot de auto-respuesta** por reglas + plantillas, con menú interactivo de bienvenida y comportamiento anti-baneo.
2. **Detección automática de clientes interesados** por scoring de palabras clave, que mueve el lead a `INTERESADO` automáticamente.
3. **UI de administración** (solo admin) para reglas y toggle del bot por cuenta.

## Decisiones ya tomadas (con el usuario)

- Motor: **reglas + plantillas** (sin IA; migrable después).
- El bot interviene **solo si nadie responde** — se calla si un humano conversó en ese chat en las últimas 4 horas. Interruptor on/off por cuenta.
- **Menú interactivo** de bienvenida (lista nativa con fallback a menú numerado de texto).
- Fuera de alcance: IA generativa, flujos multi-paso (F6), campañas salientes masivas.

## Principio anti-baneo (transversal)

1. **Nunca responder inline en el webhook** — solo despachar un job diferido.
2. **Delay aleatorio** de 25-90 segundos (`->delay(now()->addSeconds(rand(25, 90)))`).
3. **Presencia "escribiendo..."** vía Evolution (`POST /chat/sendPresence/{instancia}`) durante 3-8 segundos, proporcional al largo del texto (~60ms/caracter, clamp 3000-8000ms).
4. **Límites duros:** máx. 1 respuesta de bot por chat por minuto; máx. 20 por cuenta por hora. Al superarse → job descarta silenciosamente.
5. El bot **jamás inicia** conversaciones.

## Modelo de datos (migraciones)

```php
// add_bot_activo_to_whatsapp_cuentas
$table->boolean('bot_activo')->default(false);

// add_interes_y_silencio_to_whatsapp_chats
$table->integer('interes_score')->default(0);
$table->dateTime('bot_silenciado_hasta')->nullable();

// create_whatsapp_bot_reglas_table
Schema::create('whatsapp_bot_reglas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('cuenta_id')->nullable()->constrained('whatsapp_cuentas')->cascadeOnDelete(); // null = todas
    $table->string('nombre', 100);
    $table->enum('tipo', ['texto', 'menu'])->default('texto');
    $table->boolean('es_bienvenida')->default(false);
    $table->json('palabras_clave')->nullable();
    $table->text('respuesta')->nullable();
    $table->string('menu_titulo', 150)->nullable();
    $table->json('opciones')->nullable(); // [{"id":"op_planes","texto":"Planes","regla_id":5}, ...]
    $table->integer('prioridad')->default(0);
    $table->boolean('activa')->default(true);
    $table->timestamps();
});
```

No hace falta tabla de cola propia: la cola es **Redis + jobs de Laravel** (`ResponderBotWhatsApp` job con delay). El estado de descarte se resuelve dentro del job re-verificando condiciones al ejecutar.

## Componentes

### `App\Services\WhatsApp\BotResponder` (servicio nuevo, testeable puro)

- `decidir(WhatsAppChat $chat, string $textoEntrante, ?string $opcionSeleccionadaId, bool $esPrimerMensaje): ?WhatsAppBotRegla` — matching puro: (a) opción de lista/botón por `opciones[].id`; (b) texto que es un número N y el último mensaje out del chat fue un menú → opción N; (c) primer mensaje → regla `es_bienvenida`; (d) keywords normalizadas (minúsculas, sin tildes) por prioridad, reglas de la cuenta primero y luego globales. Sin match → `null`.
- `puntuarInteres(string $texto): int` — scoring: `precio|cuanto|costo` +2, `plan|planes|promocion` +2, `portabilidad|cambiarme` +3, `quiero|me interesa|deseo` +3, `donde|direccion|horario` +2.
- Constante de convención: la opción con `id === 'op_asesor'` silencia el bot 24h en ese chat, suma +3 de interés y responde "Listo, un asesor te escribe en breve 👋".

### Webhook (`WhatsAppWebhookController`, extender)

Tras registrar el mensaje entrante (lógica actual intacta):

1. **Scoring** (siempre): `interes_score += puntuarInteres(texto)`. Si cruza el umbral (≥5 y antes <5): badge 🔥 (campo ya en el modelo, el frontend lo lee) y, si `crm_cliente_id` no es null, buscar el `Lead` de ese cliente con estado `NUEVO` o `CONTACTADO` y moverlo a `INTERESADO` (+ registrar `InteraccionCrm` tipo WHATSAPP, detalle "Interés detectado por bot").
2. **Decisión de bot** (solo si `cuenta->bot_activo`): verificar `bot_silenciado_hasta` y "humano manda" (mensaje `out` con `enviado_por` no nulo en las últimas 4h). Si el bot debe responder, despachar `ResponderBotWhatsApp::dispatch($chat->id, $regla->id)->delay(now()->addSeconds(rand(25, 90)))`.

### Job `App\Jobs\ResponderBotWhatsApp`

Al ejecutar:
1. Re-verificar: `bot_activo`, `bot_silenciado_hasta`, "humano manda" → abortar silenciosamente si aplica (log `whatsapp.bot_descartado` con motivo).
2. Límites: contar mensajes de bot (`enviado_por IS NULL` y `direccion='out'`) del chat en el último minuto (≥1 → abortar) y de la cuenta en la última hora (≥20 → abortar).
3. `EvolutionProvider::enviarPresencia($instancia, $jid, $delayMs)` con delay 3-8s.
4. Si la regla es `menu`: `EvolutionProvider::enviarLista(...)`; si falla, fallback a `enviarTexto` con menú numerado ("1. Planes\n2. Equipos\n3. Hablar con un asesor"). Si `texto`: `enviarTexto`.
5. Registrar `WhatsAppMensaje` con `direccion='out'`, `enviado_por=null` (null = bot; verificar que `WhatsAppController::enviarMensaje` ya setea `enviado_por` con el usuario autenticado, corregir si no).

### `EvolutionProvider` (métodos nuevos + interface)

- `enviarPresencia(string $instancia, string $jid, int $delayMs): void` → `POST /chat/sendPresence/{instancia}` `{number, presence: 'composing', delay}`.
- `enviarLista(string $instancia, string $jid, string $titulo, array $opciones): array` → `POST /message/sendList/{instancia}`; devuelve `[]` en fallo para que el job haga fallback.

### Endpoints nuevos (`WhatsAppController` + `routes/api.php`, mismo middleware del grupo whatsapp)

- `PATCH /v1/whatsapp/cuentas/{id}/bot` `{bot_activo: bool}` — solo admin.
- CRUD de reglas — solo admin: `GET /v1/whatsapp/bot-reglas`, `POST /v1/whatsapp/bot-reglas`, `PUT /v1/whatsapp/bot-reglas/{id}`, `DELETE /v1/whatsapp/bot-reglas/{id}`.

### Frontend (React)

- `CuentaSelector`: switch "Bot" junto a cada cuenta (solo admin) → mutation al PATCH.
- Botón "Reglas del bot" (solo admin) en la barra del inbox → `BotReglasModal.tsx` nuevo: tabla de reglas (nombre, tipo, keywords, activa), form crear/editar (nombre, tipo texto/menú, keywords separadas por coma, respuesta o título+opciones), eliminar con confirm.
- `ChatList`: badge 🔥 cuando `chat.interes_score >= 5` (agregar el campo al tipo `WhatsAppChat`).
- Hooks nuevos en `useWhatsApp.ts`: `useBotReglas`, `useGuardarBotRegla`, `useEliminarBotRegla`, `useToggleBotCuenta`.

## Seed inicial de reglas (seeder o en la migración)

1. **Bienvenida** (menú): "¡Hola! 👋 Gracias por escribir. ¿En qué te ayudamos?" — opciones: Planes y promociones (`op_planes`) / Equipos disponibles (`op_equipos`) / Hablar con un asesor (`op_asesor`).
2. **Planes** (texto, keywords: plan, planes, promocion, precio, cuanto) — texto editable.
3. **Equipos** (texto, keywords: equipo, celular, telefono, stock) — texto editable.
4. **Horario/ubicación** (texto, keywords: horario, direccion, donde, ubicacion) — texto editable.

## Manejo de errores

| Caso | Resultado |
|---|---|
| Evolution caído al enviar | Job loguea y termina (sin reintentos automáticos — `$tries = 1`); el humano ve el chat sin responder |
| `sendList` no soportado/falla | Fallback automático a menú numerado de texto en el mismo job |
| Regla borrada antes de ejecutar el job | Job no encuentra la regla → aborta silencioso |
| Bot apagado entre despacho y ejecución | Job re-verifica `bot_activo` y aborta |
| Queue worker caído | Los jobs quedan en Redis y se procesan al levantar; nada se pierde |

## Requisito de infraestructura

El contenedor del backend debe correr `php artisan queue:work` (verificar si ya corre para la cola de comprobantes; si no, agregar un proceso/supervisor en el deploy de Dokploy).

## Testing

- Unit: `BotResponder::decidir` (bienvenida, keywords con prioridad, opción de menú por id, número de menú, sin match) y `puntuarInteres`.
- Feature: webhook con bot activo despacha el job (Queue::fake); webhook con humano reciente NO despacha; cruce de umbral mueve el lead a INTERESADO; endpoints CRUD de reglas respetan solo-admin.
- Manual end-to-end: activar bot en la cuenta de prueba, escribir "hola" desde otro número → menú en ~1 min; responder "1" → planes; "precio" → planes; verificar 🔥 y el movimiento del lead; verificar silencio de 4h tras respuesta humana.
