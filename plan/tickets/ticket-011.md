# TICKET-011 — Pipeline de logo: flood-fill de fondo + sync con la API de facturación

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-006 (para el sync).

## Contexto
Dos features recientes del legacy (commits `829f2d1`, `3ed34e9`, `30b54c5`):
1. `config/logo_helpers.php::procesar_logo_upload()` — al subir el logo de empresa, **quita el fondo sólido por flood-fill desde las 4 esquinas** (distancia de color, tolerancia 50), redimensiona a máx. 400px y devuelve data-URI PNG transparente.
2. `gerencia/ajax_sync_logo_facturacion.php` — sincroniza ese logo con la company de la API de facturación (PUT companies, multipart). Nota conocida: la API tiene el logo del PDF oficial hardcodeado — el sync cubre los datos de la company, no cambia el PDF; documentarlo en la UI.

## Alcance
1. Verificar si `ConfiguracionController` del refactor procesa el logo al subirlo; si no, portar `procesar_logo_upload` como service PHP (GD): flood-fill esquinas, tolerancia 50, resize 400px, PNG transparente.
2. Endpoint admin `configuracion/sync-logo-facturacion` que envía el logo actual a la API externa usando la config del ticket 001/006.
3. Botón "Sincronizar logo con facturación" en la sección de facturación de la UI (ConfiguracionPage o ticket 009), con nota visible de la limitación del PDF.
4. Tests: imagen con fondo blanco sólido sale transparente; imagen ya transparente pasa intacta; sync llama a la API con multipart correcto (HTTP fake).

## Criterio de aceptación
Subir un logo JPEG con fondo blanco produce PNG transparente ≤400px; el sync responde OK contra API fake; tests verdes.
