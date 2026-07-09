# TICKET-006 — Endpoints de configuración de facturación + activar producción (configure-sunat)

- **Modelo asignado:** Opus 4.8
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-001.

## Contexto
El legacy permite al admin configurar la facturación por tienda y **activar producción** subiendo el certificado digital + credenciales SOL a la API externa (`configure-sunat`, multipart). Incluye **conversión automática PFX→PEM**: `_cert_es_pem()` detecta por marcador `-----BEGIN`; `_convertir_pfx_a_pem()` usa OpenSSL CLI con flag `-legacy` (los PFX viejos de SUNAT usan RC2/3DES que OpenSSL 3.x rechaza); temporales limpiados. Referencias legacy: `gerencia/ajax_configurar_sunat.php` (193 líneas, commit `a500e49`), `dd68718` (PFX→PEM).

## Alcance
1. `FacturacionConfigController` (API, rol admin): CRUD de config por tienda + global (modelo del ticket 001).
2. Endpoint `configure-sunat`: upload de certificado (validación `file/mimes`, tamaño), conversión PFX→PEM en capa service (port de la lógica `-legacy`, limpieza de temporales), reenvío multipart a la API externa con credenciales SOL.
3. Certificados en `storage/app/private/sunat/<emisor>/` (volumen persistente — riesgo de redeploy señalado por Codex). Passwords/clave SOL cifradas si se persisten; NUNCA en logs.
4. Tests: CRUD con permisos (admin sí, tienda no), detección PEM vs PFX, conversión con fixture PFX de prueba, rechazo de MIME inválido.

## Qué NO hacer
No guardar certificados en `public/` ni como base64 en `configuracion_empresa`; no procesar certificados desde React; no loggear secretos.

## Criterio de aceptación
Flujo completo probado en local contra API fake (config → subir PFX → conversión → configure-sunat OK); tests verdes; certificado queda en storage privado.
