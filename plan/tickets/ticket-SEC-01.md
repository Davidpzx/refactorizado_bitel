# TICKET-SEC-01 — Eliminar API key hardcodeada del integrador (CRÍTICA)

**Modelo:** Opus 4.8. **Skills:** ninguna de UI aplica (es backend puro); no hace falta agentbrowser.
**Regla 0.3:** completar entero en esta pasada, no dejarlo a medias.

## Contexto
`backend/config/services.php:41` tiene:
```php
'api_key' => env('INTEGRADOR_API_KEY', 'KyrO+-tomowrroland-skrillex-2026?-wazak-vegetta777'),
```
Esa clave por defecto está commiteada en texto plano y autentica a los agentes extractores M2M (`/v1/integrador/*`). Se usa en `backend/app/Http/Controllers/Api/IntegradorController.php`:
- línea 442: `$apiKeyCentral = config('services.integrador.api_key');` (se entrega al agente vía `descargar_agente`)
- línea 560: `if (($data['api_key'] ?? '') !== config('services.integrador.api_key')) { ... }` (valida la clave que manda el agente al hacer POST de saldo/morosidad/histórico)

## Qué hacer (solo la parte de código — la rotación real de la clave en el VPS y en los agentes desplegados en tienda la coordina el usuario aparte, NO la toques)
1. En `backend/config/services.php:41`, quitar el default hardcodeado: `'api_key' => env('INTEGRADOR_API_KEY'),` (sin segundo argumento).
2. Falla rápido si falta la env var: agrega una validación de arranque (por ejemplo en `AppServiceProvider::boot()` o un `ServiceProvider` dedicado) que, si `app()->environment('production')` y `config('services.integrador.api_key')` está vacío/null, lance una excepción clara al boot (no en cada request). En entornos no-production (local/testing) puede quedar vacío sin romper el arranque, para no romper el resto de la suite de tests.
3. En `IntegradorController.php:560` (y en cualquier otro sitio que compare la api_key), verifica explícitamente que la clave configurada NO esté vacía/null antes de comparar — si `config('services.integrador.api_key')` es null/'', debe rechazar la request con 403 aunque el request tampoco mande `api_key` (evita que `null !== null` o `'' !== ''` cuele como válido). Aplica lo mismo en la línea 442 si de ahí se construye el archivo de config del agente: no generar/entregar el archivo si la key central está vacía, responder error controlado en su lugar.
4. Añade/actualiza tests en `backend/tests/Feature/IntegradorRecibirSaldoTest.php` (u otro Feature test relevante) cubriendo: (a) request con api_key correcta sigue funcionando igual que antes, (b) request con api_key vacía/incorrecta sigue devolviendo 403, (c) un test unitario o de config que confirme que `config('services.integrador.api_key')` ya no tiene el valor hardcodeado como default cuando la env var no está seteada.
5. Corre la suite completa del backend (`php artisan test` o el comando que uses normalmente en este repo) y confirma que sigue en verde antes de reportar terminado.

## Qué NO hacer
- No toques `.env` del VPS ni ningún agente desplegado en tienda — eso lo coordina el usuario directamente, es un paso operativo fuera de este ticket.
- No inventes un nuevo mecanismo de secretos (Vault, AWS Secrets Manager, etc.) — el resto del proyecto usa `env()` + `.env`, mantente en ese patrón.

## Criterio de aceptación
- `config/services.php` sin secreto hardcodeado.
- Arranque en producción falla explícitamente si falta `INTEGRADOR_API_KEY`; en local/testing no rompe nada.
- Comparación de api_key rechaza vacíos por ambos lados.
- Suite completa del backend en verde.
- No se tocó nada fuera de `backend/` (rutas, `IntegradorController`, `services.php`, provider de validación, tests).

## Al terminar
Deja un resumen corto en `plan/.worker-titan-SEC-01.log` (o el log que uses) con: archivos tocados, resultado de tests, y qué falta operativamente (la rotación real de la clave, para que el orquestador se lo recuerde al usuario). No hagas commit — el orquestador revisa el diff e integra.
