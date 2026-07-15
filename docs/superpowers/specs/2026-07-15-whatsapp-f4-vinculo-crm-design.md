# F4 — Vínculo Pipeline↔WhatsApp (refactorizado_bitel) Design

## Contexto

F1-F3 ya construyeron un inbox interno de WhatsApp multi-cuenta (`CrmWhatsAppTab.tsx`) sobre Evolution API. En paralelo, `CrmPipelineTab.tsx` ya muestra el teléfono del cliente de cada lead como un enlace `wa.me/51{telefono}` que abre WhatsApp externo, sin registro ni historial en el sistema.

F4 conecta ambas partes: ese enlace deja de apuntar a `wa.me` y en su lugar abre (o crea) la conversación correspondiente dentro del inbox interno ya construido.

## Objetivo

Al hacer clic en el teléfono de un lead con cliente asignado:
1. Se resuelve qué cuenta de WhatsApp conectada debe usarse (la de la tienda del lead, o Central si la tienda no tiene una propia).
2. Se busca o crea el `WhatsAppChat` correspondiente a ese número dentro de esa cuenta, vinculándolo a `crm_cliente_id`.
3. La UI navega a la pestaña WhatsApp (`CrmPage.tsx`, `tab === 'whatsapp'`) con esa conversación abierta, lista para escribir (con historial si ya existía).

## Fuera de alcance

- Envío automático de un primer mensaje (el agente escribe manualmente tras abrir el chat).
- Mostrar el nombre del cliente CRM en vez del nombre de WhatsApp dentro del inbox (queda para una iteración futura, aunque el dato `crm_cliente_id` ya se guarda).
- Bot de auto-respuesta (fase F5, aparte).

## Selección de cuenta

Orden de resolución, dado `tienda_id` del lead:
1. `WhatsAppCuenta` con `tienda_id = lead.tienda_id` y `estado = 'conectada'`.
2. Si no existe, `WhatsAppCuenta` con `tienda_id = null` (Central) y `estado = 'conectada'`.
3. Si ninguna cumple, error controlado (ver Manejo de errores).

## Normalización de número → JID

`cliente.telefono` viene como número local peruano (ej. `917930560`, 9 dígitos, sin `+51`). Se normaliza así antes de construir el JID:
- Quitar todo lo que no sea dígito.
- Si el resultado tiene 9 dígitos y empieza con `9`, anteponer `51`.
- Si ya trae `51` de prefijo (11 dígitos), se deja tal cual.
- JID final: `{numero_normalizado}@s.whatsapp.net`.

Esta normalización se extrae a un método reutilizable (`WhatsAppChat::normalizarJid()` o un helper estático) porque también la necesitará el webhook a futuro para matchear contactos entrantes.

## Backend: nuevo endpoint

`POST /v1/whatsapp/chats/iniciar` en `WhatsAppController`.

**Body:** `{ telefono, nombre_contacto?, tienda_id?, crm_cliente_id? }` (`nombre_contacto`/`crm_cliente_id` solo se usan si el chat es nuevo, para no pisar datos si ya existe).

**Lógica:**
1. Mismo middleware/roles que ya protege el resto de `whatsapp/*` (cualquier rol que vea el Pipeline puede contactar — no restringir a `esAdministrador()` como sí hace crear-cuenta/eliminar).
2. Resolver cuenta según "Selección de cuenta" (reutiliza `cuentasVisiblesQuery` + filtro por tienda/estado ya existente en el controller).
3. Si no hay cuenta conectada disponible → `422` con `{ message: 'sin_cuenta' }`.
4. Normalizar JID.
5. `WhatsAppChat::firstOrCreate(['cuenta_id' => ..., 'jid' => ...], ['nombre_contacto' => ..., 'numero_contacto' => $telefono, 'crm_cliente_id' => ..., 'no_leidos' => 0])`.
6. Responder `{ cuenta_id, chat_id: $chat->id }`.

**Autorización de tienda:** el lead que llega al frontend ya está scopeado por el `TiendaGuard` existente en el listado de leads, así que no hace falta revalidar tienda del lead — pero la resolución de cuenta debe reusar `TiendaGuard`/`veTodasLasTiendas()` igual que el resto del controller, para que un `jefe_tienda` no pueda forzar una cuenta de otra tienda vía payload manipulado.

## Frontend (React)

- Nuevo hook `useIniciarChatWhatsApp()` en `hooks/useWhatsApp.ts` (mutation → `whatsappApi.chats.iniciar(data)`), y su entrada correspondiente en `services/whatsapp.api.ts`.
- En `CrmPipelineTab.tsx`, el `<a href="wa.me/...">` se reemplaza por un `<button>` que llama a una función `handleContactar(lead)`:
  1. Llama la mutation `iniciarChat`.
  2. Si falla, `toast`/alerta con el mensaje de error (reusar el mecanismo de notificación ya usado en el resto del Pipeline).
  3. Si tiene éxito, necesita comunicarle a `CrmPage.tsx` que cambie a `tab === 'whatsapp'` y le pase el `chat_id`/`cuenta_id` a preseleccionar — revisar cómo `CrmPage.tsx` maneja el estado de `tab` (probablemente state local o query param) y si hace falta subir estado o usar un query param `?chat=` para que `CrmWhatsAppTab` lo lea al montar.
- `CrmWhatsAppTab.tsx` necesita aceptar una preselección inicial de cuenta/chat (prop o lectura de query param) para abrir directo en el chat correcto en vez de arrancar en "Todas las cuentas" sin selección.

## Manejo de errores

| Caso | Resultado |
|---|---|
| Lead sin teléfono | El enlace ya no se renderiza (comportamiento actual, sin cambios) |
| Sin cuenta conectada para tienda ni Central | Toast de error, no cambia de pestaña |
| Fallo de red al crear/buscar chat | Toast de error genérico, no cambia de pestaña |
| Chat ya existente | Se abre con su historial normal, no se duplica |

## Testing

- `npx tsc -b` limpio en cada task.
- Prueba manual: lead con teléfono real de una tienda con cuenta conectada → clic → debe caer en la pestaña WhatsApp con la conversación abierta (vacía si es la primera vez).
- Prueba manual: lead de una tienda sin cuenta conectada y sin cuenta Central conectada → debe mostrar el error sin romper el Pipeline.
