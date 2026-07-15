# WhatsApp multi-cuenta en el CRM + split de estadísticas — Diseño (refactorizado_bitel)

Fecha: 2026-07-14 · Spec espejo del diseño compartido con **sistema-rolando-salas** (`docs/superpowers/specs/2026-07-14-whatsapp-crm-design.md` en ese repo). Este documento detalla la adaptación a este stack (Laravel 12 + React 19).

## Objetivo

1. Sub-pestaña de WhatsApp dentro de `/crm`: inbox estilo "WhatsApp Web propio" con múltiples cuentas/números y selector de cuenta (referencia visual: dropdown de cuentas con punto verde de estado, "Agregar otro número", lista de chats + conversación).
2. Partir `CrmPage.tsx` (935 líneas, gráficos mezclados) en sub-pestañas: **Pipeline | WhatsApp | Estadísticas**, con estadísticas diferenciadas por rol.

## Decisiones tomadas (con David)

- **Híbrido de proveedor:** Evolution API (no oficial, multi-cuenta vía QR, gratis) día 1, con capa `WhatsAppProvider` intercambiable para migrar a **Watchimp** (partner de Meta) cuando haya suscripción. Pueden convivir por cuenta.
- **MVP:** inbox completo multi-cuenta (chats, conversación texto+imágenes, responder, selector de cuenta, panel QR).
- **Roles:** `administrador`, `gerente`, `jefe_tienda` (matriz de `plan/16-plan-roles.md`).
- **Scoping por tienda (fail-closed):** cuenta asignada a tienda o "Central". Jefe de tienda solo ve cuentas de su tienda; admin/gerente ven todas + vista combinada "Todas las cuentas" con badge de tienda por chat. "Agregar otro número" solo admin.
- **Infra:** contenedor Evolution API propio en el VPS (Dokploy) de este despliegue; no comparte sesiones con otros clientes.
- **Estadísticas por rol:** gerente = completa con filtros de todo (fechas, tienda, agente, categoría, canal); admin = gráficos actuales digeribles; jefe de tienda = resumen simple de su tienda.

## Arquitectura

```
Chips dedicados → QR → Evolution API (Docker, VPS)
                          │ REST + webhook
                          ▼
              Laravel: app/Services/WhatsApp/
                ├─ WhatsAppProvider (interfaz)
                ├─ EvolutionProvider
                └─ WatchimpProvider (F5)
                          │ espejo en MySQL
                          ▼
              React: /crm → CrmWhatsAppTab
```

- API key de Evolution solo en `.env` del backend. El frontend consume únicamente `v1/whatsapp/*` protegido por los middlewares `role:` y el guard de tienda existentes.
- Webhook público firmado (`v1/whatsapp/webhook` + token secreto) guarda mensajes entrantes en BD; imágenes se descargan a storage propio (las URLs de Evolution expiran).
- Vínculo CRM: match por número contra `crm_clientes` → `whatsapp_chats.crm_cliente_id`.

## Modelo de datos (migraciones)

```
whatsapp_cuentas:  id, nombre, numero, instancia, provider('evolution'|'watchimp'),
                   tienda_id NULL, estado('conectada'|'desconectada'|'qr_pendiente'), timestamps
whatsapp_chats:    id, cuenta_id FK, jid, nombre_contacto, numero_contacto,
                   crm_cliente_id NULL, ultimo_mensaje_at, no_leidos
whatsapp_mensajes: id, chat_id FK, direccion('in'|'out'), tipo('texto'|'imagen'|'audio'|'documento'),
                   contenido, media_url NULL, wa_message_id, enviado_por NULL, timestamp
```

## Endpoints (`routes/api.php`, grupo `v1/whatsapp`)

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| GET | `cuentas` | adm/ger/jt (scoped) | Cuentas visibles según rol/tienda |
| POST | `cuentas` | admin | Crear instancia + devolver QR |
| GET | `cuentas/{id}/qr` | admin | Re-obtener QR / estado |
| DELETE | `cuentas/{id}` | admin | Desconectar y eliminar |
| GET | `chats?cuenta_id=` | adm/ger/jt (scoped) | Lista de chats (o todas las cuentas visibles si se omite) |
| GET | `chats/{id}/mensajes` | adm/ger/jt (scoped) | Historial paginado |
| POST | `chats/{id}/mensajes` | adm/ger/jt (scoped) | Enviar texto/media vía provider |
| POST | `webhook` | público firmado | Entrantes desde Evolution |

Scoping fail-closed en cada endpoint: jefe_tienda con cuenta ajena → 403.

## Frontend

- `CrmPage.tsx` se parte en: `crm/CrmPipelineTab.tsx` (lo operativo actual sin gráficos), `crm/CrmWhatsAppTab.tsx` (inbox nuevo), `crm/CrmEstadisticasTab.tsx` (gráficos por rol). `CrmPage.tsx` queda como shell de pestañas.
- Inbox: header con dropdown de cuentas (nombre + número + punto de estado, check en activa, "Agregar otro número" solo admin → modal QR), buscador + lista de chats con badge de cuenta/tienda en vista combinada, panel de conversación con burbujas y composer.
- Actualización: polling corto sobre el espejo local (SSE como mejora futura).
- Estadísticas: la pestaña renderiza según `userRole` — `EstadisticasGerente` (filtros completos), `EstadisticasAdmin` (gráficos actuales), `EstadisticasJefeTienda` (KPIs simples de su tienda).

## Fases

1. **F1** — Split de `CrmPage.tsx` en 3 pestañas + estadísticas por rol.
2. **F2** — Evolution en VPS + migraciones + `WhatsAppProvider`/`EvolutionProvider` + webhook.
3. **F3** — `CrmWhatsAppTab` completo con scoping por tienda + panel QR.
4. **F4** — Vínculo con `crm_clientes` + botón WhatsApp en cada ficha del pipeline.
5. **F5 (futuro)** — `WatchimpProvider` al activar la suscripción; migración cuenta por cuenta.

## Riesgos

- **Ban de Meta (Evolution no oficial):** chips dedicados, volumen conversacional, plan de salida a Watchimp ya diseñado. Informar al cliente.
- **Caída del contenedor:** historial en MySQL propio; healthcheck en Dokploy.

## Fuera de alcance

Campañas/envíos masivos (llegan con Watchimp), bots/auto-respuestas, notas de voz salientes.
