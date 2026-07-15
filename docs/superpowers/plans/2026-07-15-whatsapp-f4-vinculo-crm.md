# F4 — Vínculo Pipeline↔WhatsApp (refactorizado_bitel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El teléfono del cliente en cada `LeadCard` del Pipeline deja de ser un enlace `wa.me` externo y en su lugar abre (o crea) la conversación correspondiente dentro del inbox interno de WhatsApp ya construido en F3.

**Architecture:** Nuevo endpoint `POST /v1/whatsapp/chats/iniciar` en `WhatsAppController` que resuelve la cuenta de WhatsApp de la tienda del lead (o Central) y hace find-or-create de `WhatsAppChat`. `CrmPage.tsx` centraliza la orquestación: recibe el lead desde `CrmPipelineTab`, llama la mutation, cambia a la pestaña WhatsApp y le pasa una preselección a `CrmWhatsAppTab`.

**Tech Stack:** Laravel (PHPUnit `RefreshDatabase` para tests), React 19 + TypeScript + TanStack Query.

## Global Constraints

- `npx tsc -b` debe pasar limpio después de cada task de frontend.
- `php artisan test --filter=WhatsApp` debe pasar limpio después de cada task de backend.
- Middleware del nuevo endpoint: `role:administrador,gerente,jefe_tienda` — mismo grupo de rutas que el resto de `whatsapp/*` en `routes/api.php`.
- Un `jefe_tienda` nunca debe poder forzar la resolución de cuenta hacia la tienda de otro jefe manipulando el body del POST — la tienda debe re-derivarse de `Auth::user()->tienda_id` en el servidor para ese rol.

---

### Task 1: Helper de normalización de número a JID

**Files:**
- Modify: `backend/app/Models/WhatsAppChat.php`
- Test: `backend/tests/Unit/WhatsAppChatNormalizarJidTest.php`

**Interfaces:**
- Produces: `WhatsAppChat::normalizarJid(string $telefono): string` (método estático)

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Unit/WhatsAppChatNormalizarJidTest.php`:

```php
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
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```
cd backend && php artisan test --filter=WhatsAppChatNormalizarJidTest
```
Esperado: FAIL (`Call to undefined method App\Models\WhatsAppChat::normalizarJid()`).

- [ ] **Step 3: Implementar el método**

En `backend/app/Models/WhatsAppChat.php`, agregar dentro de la clase (después de la propiedad `$casts`):

```php
    public static function normalizarJid(string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', $telefono) ?? '';
        if (strlen($digitos) === 9 && $digitos[0] === '9') {
            $digitos = '51' . $digitos;
        }

        return $digitos . '@s.whatsapp.net';
    }
```

- [ ] **Step 4: Ejecutar y confirmar que pasa**

```
cd backend && php artisan test --filter=WhatsAppChatNormalizarJidTest
```
Esperado: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/WhatsAppChat.php backend/tests/Unit/WhatsAppChatNormalizarJidTest.php
git commit -m "feat(whatsapp): helper de normalizacion de numero a JID"
```

---

### Task 2: Endpoint `POST /v1/whatsapp/chats/iniciar`

**Files:**
- Modify: `backend/app/Http/Controllers/Api/WhatsAppController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/WhatsAppIniciarChatTest.php`

**Interfaces:**
- Consumes: `WhatsAppChat::normalizarJid()` (Task 1), `TiendaGuard` (ya existente), método privado `cuentasVisiblesQuery()` (ya existente en el controller — no se reutiliza directamente para el fallback Central, pero mismo patrón de scoping).
- Produces: `POST /v1/whatsapp/chats/iniciar` → `200 { cuenta_id: int, chat: WhatsAppChat }` en éxito; `422 { message: 'sin_cuenta' }` si no hay cuenta conectada disponible.

- [ ] **Step 1: Escribir los tests que fallan**

`backend/tests/Feature/WhatsAppIniciarChatTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppIniciarChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_chat_nuevo_con_la_cuenta_de_la_tienda_del_lead(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'T01', 'numero' => '1', 'instancia' => 'i1', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'Central', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => null, 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'nombre_contacto' => 'Joan',
            'tienda_id' => 'T01',
            'crm_cliente_id' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('cuenta_id', $cuenta->id);
        $this->assertDatabaseHas('whatsapp_chats', [
            'cuenta_id' => $cuenta->id,
            'jid' => '51917930560@s.whatsapp.net',
            'nombre_contacto' => 'Joan',
            'crm_cliente_id' => 5,
        ]);
    }

    public function test_reutiliza_chat_existente_en_vez_de_duplicar(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'T01', 'numero' => '1', 'instancia' => 'i1', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $chatExistente = WhatsAppChat::create([
            'cuenta_id' => $cuenta->id,
            'jid' => '51917930560@s.whatsapp.net',
            'nombre_contacto' => 'Joan Viejo',
            'numero_contacto' => '917930560',
            'no_leidos' => 3,
        ]);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('chat.id', $chatExistente->id);
        $this->assertSame(1, WhatsAppChat::where('jid', '51917930560@s.whatsapp.net')->count());
    }

    public function test_usa_cuenta_central_si_la_tienda_no_tiene_una_conectada(): void
    {
        $central = WhatsAppCuenta::create(['nombre' => 'Central', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => null, 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T99',
        ]);

        $response->assertOk();
        $response->assertJsonPath('cuenta_id', $central->id);
    }

    public function test_422_sin_cuenta_conectada_disponible(): void
    {
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'sin_cuenta');
    }

    public function test_jefe_tienda_no_puede_forzar_cuenta_de_otra_tienda(): void
    {
        WhatsAppCuenta::create(['nombre' => 'T02', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => 'T02', 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T02', // intenta forzar la tienda ajena
        ]);

        // No existe cuenta conectada para T01 (su tienda real) ni Central -> 422, nunca usa la de T02.
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'sin_cuenta');
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que fallan**

```
cd backend && php artisan test --filter=WhatsAppIniciarChatTest
```
Esperado: FAIL (ruta `chats/iniciar` no existe → 404).

- [ ] **Step 3: Agregar la ruta**

En `backend/routes/api.php`, dentro del grupo `Route::middleware('role:administrador,gerente,jefe_tienda')->prefix('whatsapp')->group(...)` (línea ~378-386), agregar antes de `chats/{id}/mensajes`:

```php
        Route::post('chats/iniciar', [WhatsAppController::class, 'iniciarChat']);
        Route::get('chats', [WhatsAppController::class, 'chats']);
```

(la línea `Route::get('chats', ...)` ya existe — solo se agrega la nueva línea `chats/iniciar` justo antes, para que Laravel no intente matchear `iniciar` como `{id}` de una ruta futura si alguna vez se agrega `chats/{id}`).

- [ ] **Step 4: Implementar el método en el controller**

En `backend/app/Http/Controllers/Api/WhatsAppController.php`, agregar después de `chats()` (o en cualquier punto dentro de la clase):

```php
    public function iniciarChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telefono' => ['required', 'string'],
            'nombre_contacto' => ['nullable', 'string', 'max:150'],
            'tienda_id' => ['nullable', 'string', 'max:10'],
            'crm_cliente_id' => ['nullable', 'integer'],
        ]);

        $tiendaId = $data['tienda_id'] ?? null;

        // Un jefe_tienda nunca resuelve cuenta fuera de su propia tienda, sin
        // importar que le hayan mandado otro tienda_id en el body.
        if (!$this->veTodasLasTiendas()) {
            $tiendaId = trim((string) Auth::user()?->tienda_id) ?: null;
        }

        $cuenta = null;
        if ($tiendaId !== null) {
            $cuenta = WhatsAppCuenta::where('tienda_id', $tiendaId)->where('estado', 'conectada')->first();
        }
        if (!$cuenta) {
            $cuenta = WhatsAppCuenta::whereNull('tienda_id')->where('estado', 'conectada')->first();
        }
        if (!$cuenta) {
            return response()->json(['message' => 'sin_cuenta'], 422);
        }

        $jid = WhatsAppChat::normalizarJid($data['telefono']);

        $chat = WhatsAppChat::firstOrCreate(
            ['cuenta_id' => $cuenta->id, 'jid' => $jid],
            [
                'nombre_contacto' => $data['nombre_contacto'] ?? null,
                'numero_contacto' => $data['telefono'],
                'crm_cliente_id' => $data['crm_cliente_id'] ?? null,
                'no_leidos' => 0,
            ]
        );

        return response()->json(['cuenta_id' => $cuenta->id, 'chat' => $chat]);
    }
```

- [ ] **Step 5: Ejecutar y confirmar que pasan**

```
cd backend && php artisan test --filter=WhatsAppIniciarChatTest
```
Esperado: PASS (5 tests).

- [ ] **Step 6: Ejecutar toda la suite de WhatsApp para descartar regresiones**

```
cd backend && php artisan test --filter=WhatsApp
```
Esperado: todos los tests existentes de WhatsApp (webhook, scoping, migraciones) siguen en PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/WhatsAppController.php backend/routes/api.php backend/tests/Feature/WhatsAppIniciarChatTest.php
git commit -m "feat(whatsapp): endpoint POST chats/iniciar para vincular desde el Pipeline"
```

---

### Task 3: Capa de API y hook de React

**Files:**
- Modify: `frontend/src/services/whatsapp.api.ts`
- Modify: `frontend/src/hooks/useWhatsApp.ts`

**Interfaces:**
- Produces: `whatsappApi.chats.iniciar(data): Promise<{cuenta_id: number; chat: WhatsAppChat}>`, `useIniciarChatWhatsApp()` (mutation hook).

- [ ] **Step 1: Agregar el método a la capa de API**

En `frontend/src/services/whatsapp.api.ts`, dentro del objeto `chats`, después de `list`:

```ts
    iniciar: (data: { telefono: string; nombre_contacto?: string; tienda_id?: string; crm_cliente_id?: number }): Promise<{ cuenta_id: number; chat: WhatsAppChat }> =>
      api.post('/v1/whatsapp/chats/iniciar', data).then(r => r.data),
```

- [ ] **Step 2: Agregar el hook**

En `frontend/src/hooks/useWhatsApp.ts`, después de `useEliminarCuentaWhatsApp`:

```ts
export function useIniciarChatWhatsApp() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (data: { telefono: string; nombre_contacto?: string; tienda_id?: string; crm_cliente_id?: number }) =>
      whatsappApi.chats.iniciar(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-chats'] }),
  })
}
```

- [ ] **Step 3: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: limpio.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/services/whatsapp.api.ts frontend/src/hooks/useWhatsApp.ts
git commit -m "feat(whatsapp): capa de API y hook para iniciar chat desde el Pipeline"
```

---

### Task 4: `CrmWhatsAppTab` acepta una conversación preseleccionada

**Files:**
- Modify: `frontend/src/pages/crm/CrmWhatsAppTab.tsx`

**Interfaces:**
- Consumes: nada nuevo de otras tasks.
- Produces: `CrmWhatsAppTab` acepta las props opcionales `chatPreseleccionado?: { cuentaId: number; chat: WhatsAppChat } | null` y `onPreseleccionConsumida?: () => void`.

- [ ] **Step 1: Actualizar la firma y agregar el efecto**

Reemplazar el inicio del componente:

```tsx
import { useState } from 'react'
import type { Usuario } from '../../types/auth'
import type { WhatsAppChat } from '../../types/whatsapp'
import { useWhatsAppChats, useWhatsAppCuentas } from '../../hooks/useWhatsApp'
import { normalizarRol } from '../../utils/roles'
import { Button } from '../../components/ui/button'
import { ChatList } from './whatsapp/ChatList'
import { ConectarCuentaModal } from './whatsapp/ConectarCuentaModal'
import { ConversacionPanel } from './whatsapp/ConversacionPanel'
import { CuentaSelector } from './whatsapp/CuentaSelector'

export function CrmWhatsAppTab({
  usuario,
  chatPreseleccionado,
  onPreseleccionConsumida,
}: {
  usuario: Usuario | null
  chatPreseleccionado?: { cuentaId: number; chat: WhatsAppChat } | null
  onPreseleccionConsumida?: () => void
}) {
  const esAdmin = normalizarRol(usuario?.rol) === 'administrador'
  const [cuentaActivaId, setCuentaActivaId] = useState<number | 'todas'>('todas')
  const [chatActivo, setChatActivo] = useState<WhatsAppChat | null>(null)
  const [modalQrAbierto, setModalQrAbierto] = useState(false)

  const { data: cuentas = [] } = useWhatsAppCuentas()
  const { data: chats = [] } = useWhatsAppChats(cuentaActivaId === 'todas' ? undefined : cuentaActivaId)

  useEffect(() => {
    if (!chatPreseleccionado) return
    setCuentaActivaId(chatPreseleccionado.cuentaId)
    setChatActivo(chatPreseleccionado.chat)
    onPreseleccionConsumida?.()
  }, [chatPreseleccionado, onPreseleccionConsumida])
```

Agregar `useEffect` al import de React:

```tsx
import { useEffect, useState } from 'react'
```

- [ ] **Step 2: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: limpio.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/crm/CrmWhatsAppTab.tsx
git commit -m "feat(whatsapp): CrmWhatsAppTab acepta chat preseleccionado"
```

---

### Task 5: `CrmPipelineTab` expone un callback `onContactar`

**Files:**
- Modify: `frontend/src/pages/crm/CrmPipelineTab.tsx`

**Interfaces:**
- Produces: `CrmPipelineTab` acepta la prop `onContactar: (lead: Lead) => void`; `LeadCard` y `KanbanColumna` la reciben y la usan en el botón de contacto.

- [ ] **Step 1: Reemplazar el enlace `wa.me` en `LeadCard`**

Cambiar la firma de `LeadCard` para aceptar `onContactar`:

```tsx
function LeadCard({
  lead,
  onEditar,
  onCambiarEstado,
  onEliminar,
  onContactar,
}: {
  lead: Lead
  onEditar: (l: Lead) => void
  onCambiarEstado: (id: number, estado: Lead['estado']) => void
  onEliminar: (id: number) => void
  onContactar: (lead: Lead) => void
}) {
```

Reemplazar el bloque del enlace de WhatsApp:

```tsx
          {lead.cliente.telefono && (
            <a
              href={`https://wa.me/51${lead.cliente.telefono}`}
              target="_blank"
              rel="noreferrer"
              className="font-medium text-kyro-success transition-colors hover:underline"
            >
              {lead.cliente.telefono}
            </a>
          )}
```

por:

```tsx
          {lead.cliente.telefono && (
            <button
              type="button"
              onClick={() => onContactar(lead)}
              className="font-medium text-kyro-success transition-colors hover:underline"
            >
              {lead.cliente.telefono}
            </button>
          )}
```

- [ ] **Step 2: Propagar `onContactar` por `KanbanColumna`**

```tsx
function KanbanColumna({
  config,
  leads,
  onEditar,
  onCambiarEstado,
  onEliminar,
  onContactar,
}: {
  config: typeof ESTADOS[0]
  leads: Lead[]
  onEditar: (l: Lead) => void
  onCambiarEstado: (id: number, estado: Lead['estado']) => void
  onEliminar: (id: number) => void
  onContactar: (lead: Lead) => void
}) {
  return (
    <div className={`flex min-h-[200px] min-w-[260px] flex-col gap-2.5 rounded-kyro-lg border p-4 shadow-kyro-card ${config.bg}`}>
      <div className="mb-1 flex items-center justify-between border-b border-kyro-border pb-2.5">
        <span className={`text-[0.7rem] font-bold uppercase tracking-[0.1em] ${config.color}`}>{config.label}</span>
        <Badge variant="outline" className="min-w-6 justify-center border-kyro-border bg-kyro-elevated text-xs">{leads.length}</Badge>
      </div>
      {leads.map(lead => (
        <LeadCard
          key={lead.id}
          lead={lead}
          onEditar={onEditar}
          onCambiarEstado={onCambiarEstado}
          onEliminar={onEliminar}
          onContactar={onContactar}
        />
      ))}
      {leads.length === 0 && (
        <p className="mt-6 rounded-kyro border border-dashed border-kyro-border py-6 text-center text-xs text-kyro-subtle">Sin leads</p>
      )}
    </div>
  )
}
```

- [ ] **Step 3: Aceptar `onContactar` en `CrmPipelineTab` y pasarla a `KanbanColumna`**

Cambiar la firma exportada:

```tsx
export function CrmPipelineTab({ onContactar }: { onContactar: (lead: Lead) => void }) {
```

En el `.map` de `KanbanColumna`:

```tsx
          {ESTADOS.map(config => (
            <KanbanColumna
              key={config.value}
              config={config}
              leads={leadsPorEstado[config.value] ?? []}
              onEditar={abrirEdicion}
              onCambiarEstado={cambiarEstado}
              onEliminar={eliminarLead}
              onContactar={onContactar}
            />
          ))}
```

- [ ] **Step 4: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: falla en este punto porque `CrmPage.tsx` todavía llama `<CrmPipelineTab />` sin la prop `onContactar` — es esperado, se corrige en la Task 6. Confirmar que el único error nuevo es exactamente ese (prop faltante en `CrmPage.tsx`), no otro.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/crm/CrmPipelineTab.tsx
git commit -m "feat(whatsapp): CrmPipelineTab expone callback onContactar"
```

---

### Task 6: Orquestación en `CrmPage`

**Files:**
- Modify: `frontend/src/pages/crm/CrmPage.tsx`

**Interfaces:**
- Consumes: `useIniciarChatWhatsApp()` (Task 3), `onContactar` de `CrmPipelineTab` (Task 5), `chatPreseleccionado`/`onPreseleccionConsumida` de `CrmWhatsAppTab` (Task 4).

- [ ] **Step 1: Implementar el archivo completo**

```tsx
import { useState } from 'react'
import { ChatCircleDots, ChartBar, Megaphone } from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { useAuth } from '../../hooks/useAuth'
import { useIniciarChatWhatsApp } from '../../hooks/useWhatsApp'
import type { Lead } from '../../types/crm'
import type { WhatsAppChat } from '../../types/whatsapp'
import { CrmEstadisticasTab } from './CrmEstadisticasTab'
import { CrmPipelineTab } from './CrmPipelineTab'
import { CrmWhatsAppTab } from './CrmWhatsAppTab'

type CrmTab = 'pipeline' | 'whatsapp' | 'estadisticas'

const TABS: { value: CrmTab; label: string; Icon: typeof Megaphone }[] = [
  { value: 'pipeline', label: 'Pipeline', Icon: Megaphone },
  { value: 'whatsapp', label: 'WhatsApp', Icon: ChatCircleDots },
  { value: 'estadisticas', label: 'Estadisticas', Icon: ChartBar },
]

export function CrmPage() {
  const [tab, setTab] = useState<CrmTab>('pipeline')
  const [chatPreseleccionado, setChatPreseleccionado] = useState<{ cuentaId: number; chat: WhatsAppChat } | null>(null)
  const { usuario } = useAuth()
  const iniciarChat = useIniciarChatWhatsApp()

  const handleContactar = (lead: Lead) => {
    if (!lead.cliente?.telefono) return

    iniciarChat.mutate(
      {
        telefono: lead.cliente.telefono,
        nombre_contacto: lead.cliente.nombre,
        tienda_id: lead.tienda_id,
        crm_cliente_id: lead.cliente.id,
      },
      {
        onSuccess: (data) => {
          setChatPreseleccionado({ cuentaId: data.cuenta_id, chat: data.chat })
          setTab('whatsapp')
        },
        onError: () => {
          alert('No hay WhatsApp conectado para tu tienda. Contacta al administrador.')
        },
      }
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title="CRM y Marketing" description="Pipeline de ventas, WhatsApp y estadisticas." Icon={Megaphone} />

      <div className="flex gap-1 border-b border-kyro-border">
        {TABS.map(({ value, label, Icon }) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={`flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
              tab === value
                ? 'border-kyro-indigo text-kyro-indigo'
                : 'border-transparent text-kyro-muted hover:text-kyro-body'
            }`}
          >
            <Icon size={15} />
            {label}
          </button>
        ))}
      </div>

      {tab === 'pipeline' && <CrmPipelineTab onContactar={handleContactar} />}
      {tab === 'whatsapp' && (
        <CrmWhatsAppTab
          usuario={usuario}
          chatPreseleccionado={chatPreseleccionado}
          onPreseleccionConsumida={() => setChatPreseleccionado(null)}
        />
      )}
      {tab === 'estadisticas' && <CrmEstadisticasTab usuario={usuario} />}
    </div>
  )
}
```

- [ ] **Step 2: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: limpio (esto también resuelve el error esperado de la Task 5).

- [ ] **Step 3: Probar en navegador**

```
cd frontend && npm run dev
```
1. Ir a `/crm`, pestaña Pipeline.
2. Clic en el teléfono de un lead con cliente asignado, de una tienda con cuenta de WhatsApp conectada.
3. Debe cambiar a la pestaña WhatsApp con la conversación abierta.
4. Clic de nuevo sobre el mismo lead (volviendo a Pipeline primero) → debe abrir el mismo chat, no uno duplicado.
5. Probar con un lead de una tienda sin cuenta conectada y sin Central conectada → debe mostrar el alert de error sin cambiar de pestaña.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/crm/CrmPage.tsx
git commit -m "feat(whatsapp): orquestar Contactar-Pipeline hacia el inbox interno"
```

---

## Fuera de alcance de este plan

- Envío automático de un primer mensaje al abrir el chat.
- Mostrar el nombre del cliente CRM en vez del nombre de WhatsApp dentro del inbox.
- Bot de auto-respuesta (F5).
