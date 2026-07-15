# F3 — Inbox UI de WhatsApp multi-cuenta (refactorizado_bitel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el placeholder de `CrmWhatsAppTab.tsx` (F1) por un inbox real: selector de cuentas con estado (referencia visual: dropdown de cuentas con punto verde, "Agregar otro número" solo admin), lista de chats con búsqueda, panel de conversación con envío de texto/imagen, y modal de QR para conectar cuentas nuevas.

**Architecture:** Sigue el patrón ya establecido (`services/crm.api.ts` + `hooks/useCrm.ts`): capa de API tipada, hooks de TanStack Query con polling corto para el efecto "tiempo real" sin WebSockets. Todo consume los endpoints ya construidos en F2 (`v1/whatsapp/cuentas`, `v1/whatsapp/chats`, `v1/whatsapp/chats/{id}/mensajes`).

**Tech Stack:** React 19 + TypeScript, TanStack Query, componentes UI existentes (`Button`, `Input`, `Dialog`, `Select` de `frontend/src/components/ui/`).

## Global Constraints

- `npx tsc -b` debe pasar limpio después de cada task.
- Reutilizar componentes UI existentes (`frontend/src/components/ui/`) — no crear un Dialog/Button propio.
- El polling de chats/mensajes no debe ser menor a 4 segundos (evitar flood al backend).
- Botón "Agregar otro número" (QR) solo visible si `usuario.rol` normalizado es `administrador`.

---

### Task 1: Capa de API y tipos

**Files:**
- Create: `frontend/src/types/whatsapp.ts`
- Create: `frontend/src/services/whatsapp.api.ts`

**Interfaces:**
- Produces: tipos `WhatsAppCuenta`, `WhatsAppChat`, `WhatsAppMensaje`; `whatsappApi.cuentas.list/create/qr/eliminar`, `whatsappApi.chats.list(cuentaId?)`, `whatsappApi.mensajes.list(chatId)`, `whatsappApi.mensajes.enviar(chatId, data)`.

- [ ] **Step 1: Tipos**

`frontend/src/types/whatsapp.ts`:
```ts
export interface WhatsAppCuenta {
  id: number
  nombre: string
  numero: string
  instancia: string
  provider: 'evolution' | 'watchimp'
  tienda_id: string | null
  estado: 'conectada' | 'desconectada' | 'qr_pendiente'
}

export interface WhatsAppChat {
  id: number
  cuenta_id: number
  jid: string
  nombre_contacto: string | null
  numero_contacto: string | null
  crm_cliente_id: number | null
  ultimo_mensaje_at: string | null
  no_leidos: number
  cuenta?: { id: number; nombre: string; tienda_id: string | null }
}

export interface WhatsAppMensaje {
  id: number
  chat_id: number
  direccion: 'in' | 'out'
  tipo: 'texto' | 'imagen' | 'documento'
  contenido: string | null
  media_url: string | null
  wa_message_id: string | null
  enviado_por: number | null
  timestamp: string
}

export interface WhatsAppMensajesPaginados {
  data: WhatsAppMensaje[]
  current_page: number
  last_page: number
}
```

- [ ] **Step 2: API**

`frontend/src/services/whatsapp.api.ts`:
```ts
import { api } from './api'
import type { WhatsAppChat, WhatsAppCuenta, WhatsAppMensaje, WhatsAppMensajesPaginados } from '../types/whatsapp'

export const whatsappApi = {
  cuentas: {
    list: (): Promise<WhatsAppCuenta[]> =>
      api.get('/v1/whatsapp/cuentas').then(r => r.data),

    create: (data: { nombre: string; numero: string; tienda_id?: string }): Promise<{ cuenta: WhatsAppCuenta; qr: string }> =>
      api.post('/v1/whatsapp/cuentas', data).then(r => r.data),

    qr: (id: number): Promise<{ estado: WhatsAppCuenta['estado']; qr: string }> =>
      api.get(`/v1/whatsapp/cuentas/${id}/qr`).then(r => r.data),

    eliminar: (id: number): Promise<void> =>
      api.delete(`/v1/whatsapp/cuentas/${id}`).then(r => r.data),
  },

  chats: {
    list: (cuentaId?: number): Promise<WhatsAppChat[]> =>
      api.get('/v1/whatsapp/chats', { params: cuentaId ? { cuenta_id: cuentaId } : {} }).then(r => r.data),
  },

  mensajes: {
    list: (chatId: number): Promise<WhatsAppMensajesPaginados> =>
      api.get(`/v1/whatsapp/chats/${chatId}/mensajes`).then(r => r.data),

    enviar: (chatId: number, data: { tipo: 'texto' | 'imagen'; contenido?: string; media_url?: string }): Promise<WhatsAppMensaje> =>
      api.post(`/v1/whatsapp/chats/${chatId}/mensajes`, data).then(r => r.data),
  },
}
```

- [ ] **Step 3: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: limpio (archivos nuevos sin consumidores todavía, no debe haber error).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/types/whatsapp.ts frontend/src/services/whatsapp.api.ts
git commit -m "feat(whatsapp): tipos y capa de API del frontend"
```

---

### Task 2: Hooks de datos con polling

**Files:**
- Create: `frontend/src/hooks/useWhatsApp.ts`

**Interfaces:**
- Produces: `useWhatsAppCuentas()`, `useWhatsAppChats(cuentaId?: number)`, `useWhatsAppMensajes(chatId: number | null)`, `useEnviarMensajeWhatsApp()`, `useCrearCuentaWhatsApp()`, `useQrCuentaWhatsApp(id: number | null)`.

- [ ] **Step 1: Implementar el archivo**

`frontend/src/hooks/useWhatsApp.ts`:
```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { whatsappApi } from '../services/whatsapp.api'

export function useWhatsAppCuentas() {
  return useQuery({
    queryKey: ['whatsapp-cuentas'],
    queryFn: () => whatsappApi.cuentas.list(),
    staleTime: 30_000,
  })
}

export function useWhatsAppChats(cuentaId?: number) {
  return useQuery({
    queryKey: ['whatsapp-chats', cuentaId ?? 'todas'],
    queryFn: () => whatsappApi.chats.list(cuentaId),
    refetchInterval: 8_000,
  })
}

export function useWhatsAppMensajes(chatId: number | null) {
  return useQuery({
    queryKey: ['whatsapp-mensajes', chatId],
    queryFn: () => whatsappApi.mensajes.list(chatId as number),
    enabled: chatId !== null,
    refetchInterval: 5_000,
  })
}

export function useEnviarMensajeWhatsApp() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ chatId, data }: { chatId: number; data: { tipo: 'texto' | 'imagen'; contenido?: string; media_url?: string } }) =>
      whatsappApi.mensajes.enviar(chatId, data),
    onSuccess: (_result, variables) => {
      qc.invalidateQueries({ queryKey: ['whatsapp-mensajes', variables.chatId] })
      qc.invalidateQueries({ queryKey: ['whatsapp-chats'] })
    },
  })
}

export function useCrearCuentaWhatsApp() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { nombre: string; numero: string; tienda_id?: string }) => whatsappApi.cuentas.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-cuentas'] }),
  })
}

export function useQrCuentaWhatsApp(id: number | null) {
  return useQuery({
    queryKey: ['whatsapp-qr', id],
    queryFn: () => whatsappApi.cuentas.qr(id as number),
    enabled: id !== null,
    refetchInterval: (query) => (query.state.data?.estado === 'conectada' ? false : 3_000),
  })
}
```

- [ ] **Step 2: Verificar tipos**

```
cd frontend && npx tsc -b
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/hooks/useWhatsApp.ts
git commit -m "feat(whatsapp): hooks de datos con polling (cuentas/chats/mensajes)"
```

---

### Task 3: Selector de cuentas + modal QR (solo admin)

**Files:**
- Create: `frontend/src/pages/crm/whatsapp/CuentaSelector.tsx`
- Create: `frontend/src/pages/crm/whatsapp/ConectarCuentaModal.tsx`

**Interfaces:**
- Produces: `CuentaSelector({ cuentas, cuentaActivaId, onSeleccionar, onAgregarNueva }: {...})`, `ConectarCuentaModal({ open, onClose }: { open: boolean; onClose: () => void })`.

- [ ] **Step 1: CuentaSelector**

`frontend/src/pages/crm/whatsapp/CuentaSelector.tsx`:
```tsx
import { useState } from 'react'
import { CaretDown, Circle, Plus } from '@phosphor-icons/react'
import type { WhatsAppCuenta } from '../../../types/whatsapp'

export function CuentaSelector({
  cuentas, cuentaActivaId, onSeleccionar, onAgregarNueva, esAdmin,
}: {
  cuentas: WhatsAppCuenta[]
  cuentaActivaId: number | 'todas'
  onSeleccionar: (id: number | 'todas') => void
  onAgregarNueva: () => void
  esAdmin: boolean
}) {
  const [abierto, setAbierto] = useState(false)
  const activa = cuentaActivaId === 'todas' ? null : cuentas.find(c => c.id === cuentaActivaId)

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setAbierto(v => !v)}
        className="flex items-center gap-2 rounded-kyro border border-kyro-border bg-kyro-surface px-3 py-2 text-sm font-medium hover:border-kyro-indigo"
      >
        <Circle weight="fill" size={8} className={activa?.estado === 'conectada' ? 'text-kyro-success' : 'text-kyro-muted'} />
        {activa ? `${activa.nombre} · ${activa.numero}` : 'Todas las cuentas'}
        <CaretDown size={12} />
      </button>

      {abierto && (
        <div className="absolute left-0 top-full z-20 mt-1 w-72 rounded-kyro border border-kyro-border bg-kyro-surface p-1 shadow-lg">
          <button
            type="button"
            onClick={() => { onSeleccionar('todas'); setAbierto(false) }}
            className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-kyro-border/40"
          >
            Todas las cuentas
          </button>
          {cuentas.map(c => (
            <button
              key={c.id}
              type="button"
              onClick={() => { onSeleccionar(c.id); setAbierto(false) }}
              className="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-kyro-border/40"
            >
              <span className="flex items-center gap-2">
                <Circle weight="fill" size={8} className={c.estado === 'conectada' ? 'text-kyro-success' : 'text-kyro-muted'} />
                {c.nombre}
              </span>
              <span className="text-xs text-kyro-muted">{c.numero}</span>
            </button>
          ))}
          {esAdmin && (
            <button
              type="button"
              onClick={() => { onAgregarNueva(); setAbierto(false) }}
              className="mt-1 flex w-full items-center gap-2 rounded-md border-t border-kyro-border px-3 py-2 text-left text-sm text-kyro-indigo hover:bg-kyro-border/40"
            >
              <Plus size={14} /> Agregar otro número
            </button>
          )}
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 2: ConectarCuentaModal**

`frontend/src/pages/crm/whatsapp/ConectarCuentaModal.tsx`:
```tsx
import { useState } from 'react'
import { Dialog } from '../../../components/ui/dialog'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'
import { useCrearCuentaWhatsApp, useQrCuentaWhatsApp } from '../../../hooks/useWhatsApp'

export function ConectarCuentaModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [nombre, setNombre] = useState('')
  const [numero, setNumero] = useState('')
  const [tiendaId, setTiendaId] = useState('')
  const [cuentaCreadaId, setCuentaCreadaId] = useState<number | null>(null)

  const crear = useCrearCuentaWhatsApp()
  const qrQuery = useQrCuentaWhatsApp(cuentaCreadaId)

  const cerrar = () => {
    setNombre(''); setNumero(''); setTiendaId(''); setCuentaCreadaId(null)
    onClose()
  }

  const handleCrear = () => {
    crear.mutate({ nombre, numero, tienda_id: tiendaId || undefined }, {
      onSuccess: (resultado) => setCuentaCreadaId(resultado.cuenta.id),
    })
  }

  return (
    <Dialog open={open} onClose={cerrar} title="Agregar número de WhatsApp" maxWidth="sm">
      {!cuentaCreadaId ? (
        <div className="space-y-3">
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Nombre de la cuenta</label>
            <Input value={nombre} onChange={e => setNombre(e.target.value)} placeholder="Tienda Centro" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Número</label>
            <Input value={numero} onChange={e => setNumero(e.target.value)} placeholder="+51999999999" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Tienda (opcional, vacío = Central)</label>
            <Input value={tiendaId} onChange={e => setTiendaId(e.target.value)} placeholder="T01" />
          </div>
          <Button variant="gold" className="w-full" disabled={!nombre || !numero || crear.isPending} onClick={handleCrear}>
            {crear.isPending ? 'Creando...' : 'Crear y generar QR'}
          </Button>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          {qrQuery.data?.estado === 'conectada' ? (
            <p className="text-sm text-kyro-success">Cuenta conectada correctamente.</p>
          ) : qrQuery.data?.qr ? (
            <>
              <img src={`data:image/png;base64,${qrQuery.data.qr}`} alt="Código QR de WhatsApp" className="h-56 w-56 rounded-kyro border border-kyro-border" />
              <p className="text-xs text-kyro-muted">Escanea este código desde WhatsApp → Dispositivos vinculados.</p>
            </>
          ) : (
            <p className="text-sm text-kyro-muted">Generando código QR...</p>
          )}
          <Button variant="outline" onClick={cerrar}>Cerrar</Button>
        </div>
      )}
    </Dialog>
  )
}
```

- [ ] **Step 3: Verificar tipos**

```
cd frontend && npx tsc -b
```
Revisar la firma real de `Dialog` (`frontend/src/components/ui/dialog.tsx`) antes de asumir que acepta `maxWidth="sm"` — usar el valor real que acepte ese prop si difiere (ya se usó `maxWidth="lg"` en `TicketsPage.tsx`, confirmar los valores válidos del tipo).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/crm/whatsapp/CuentaSelector.tsx frontend/src/pages/crm/whatsapp/ConectarCuentaModal.tsx
git commit -m "feat(whatsapp): selector de cuentas + modal de conexion QR"
```

---

### Task 4: Lista de chats + panel de conversación

**Files:**
- Create: `frontend/src/pages/crm/whatsapp/ChatList.tsx`
- Create: `frontend/src/pages/crm/whatsapp/ConversacionPanel.tsx`

**Interfaces:**
- Produces: `ChatList({ chats, chatActivoId, onSeleccionar, mostrarBadgeCuenta }: {...})`, `ConversacionPanel({ chat }: { chat: WhatsAppChat | null })`.

- [ ] **Step 1: ChatList**

`frontend/src/pages/crm/whatsapp/ChatList.tsx`:
```tsx
import { useState } from 'react'
import { MagnifyingGlass } from '@phosphor-icons/react'
import type { WhatsAppChat } from '../../../types/whatsapp'
import { Input } from '../../../components/ui/input'

export function ChatList({
  chats, chatActivoId, onSeleccionar, mostrarBadgeCuenta,
}: {
  chats: WhatsAppChat[]
  chatActivoId: number | null
  onSeleccionar: (chat: WhatsAppChat) => void
  mostrarBadgeCuenta: boolean
}) {
  const [busqueda, setBusqueda] = useState('')

  const filtrados = chats.filter(c => {
    const texto = `${c.nombre_contacto ?? ''} ${c.numero_contacto ?? ''}`.toLowerCase()
    return texto.includes(busqueda.toLowerCase())
  })

  return (
    <div className="flex h-full flex-col border-r border-kyro-border">
      <div className="border-b border-kyro-border p-2">
        <Input
          value={busqueda}
          onChange={e => setBusqueda(e.target.value)}
          placeholder="Buscar chat..."
          className="h-9"
        />
      </div>
      <div className="flex-1 overflow-y-auto">
        {filtrados.length === 0 && (
          <p className="p-4 text-center text-xs text-kyro-muted">Sin conversaciones.</p>
        )}
        {filtrados.map(chat => (
          <button
            key={chat.id}
            type="button"
            onClick={() => onSeleccionar(chat)}
            className={`flex w-full items-center gap-3 border-b border-kyro-border/60 px-3 py-2.5 text-left transition-colors ${
              chatActivoId === chat.id ? 'bg-kyro-indigo/10' : 'hover:bg-kyro-border/30'
            }`}
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-kyro-indigo/15 text-xs font-semibold text-kyro-indigo">
              {(chat.nombre_contacto ?? chat.numero_contacto ?? '?').slice(0, 2).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center justify-between gap-2">
                <span className="truncate text-sm font-medium">{chat.nombre_contacto ?? chat.numero_contacto ?? 'Desconocido'}</span>
                {chat.no_leidos > 0 && (
                  <span className="flex h-4 min-w-4 items-center justify-center rounded-full bg-kyro-indigo px-1 text-[10px] font-bold text-white">
                    {chat.no_leidos}
                  </span>
                )}
              </div>
              {mostrarBadgeCuenta && chat.cuenta && (
                <span className="text-[10px] text-kyro-muted">{chat.cuenta.nombre}</span>
              )}
            </div>
          </button>
        ))}
      </div>
    </div>
  )
}
```

- [ ] **Step 2: ConversacionPanel**

`frontend/src/pages/crm/whatsapp/ConversacionPanel.tsx`:
```tsx
import { useState } from 'react'
import { PaperPlaneRight } from '@phosphor-icons/react'
import type { WhatsAppChat } from '../../../types/whatsapp'
import { useEnviarMensajeWhatsApp, useWhatsAppMensajes } from '../../../hooks/useWhatsApp'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'

export function ConversacionPanel({ chat }: { chat: WhatsAppChat | null }) {
  const [texto, setTexto] = useState('')
  const { data } = useWhatsAppMensajes(chat?.id ?? null)
  const enviar = useEnviarMensajeWhatsApp()

  if (!chat) {
    return (
      <div className="flex h-full flex-1 items-center justify-center text-sm text-kyro-muted">
        Selecciona una conversación.
      </div>
    )
  }

  const handleEnviar = () => {
    const contenido = texto.trim()
    if (!contenido) return
    enviar.mutate({ chatId: chat.id, data: { tipo: 'texto', contenido } })
    setTexto('')
  }

  return (
    <div className="flex h-full flex-1 flex-col">
      <div className="border-b border-kyro-border px-4 py-3">
        <p className="text-sm font-semibold">{chat.nombre_contacto ?? chat.numero_contacto}</p>
        <p className="text-xs text-kyro-muted">{chat.numero_contacto}</p>
      </div>

      <div className="flex-1 space-y-2 overflow-y-auto p-4">
        {(data?.data ?? []).map(m => (
          <div key={m.id} className={`flex ${m.direccion === 'out' ? 'justify-end' : 'justify-start'}`}>
            <div
              className={`max-w-[70%] rounded-kyro px-3 py-2 text-sm ${
                m.direccion === 'out' ? 'bg-kyro-indigo text-white' : 'bg-kyro-border/40 text-kyro-body'
              }`}
            >
              {m.tipo === 'imagen' && m.media_url && (
                <img src={m.media_url} alt="Imagen" className="mb-1 max-w-full rounded" />
              )}
              {m.contenido && <p>{m.contenido}</p>}
              <p className="mt-1 text-right text-[10px] opacity-70">
                {new Date(m.timestamp).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}
              </p>
            </div>
          </div>
        ))}
      </div>

      <div className="flex items-center gap-2 border-t border-kyro-border p-3">
        <Input
          value={texto}
          onChange={e => setTexto(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') handleEnviar() }}
          placeholder="Escribe un mensaje..."
          className="flex-1"
        />
        <Button variant="gold" size="icon" disabled={!texto.trim() || enviar.isPending} onClick={handleEnviar}>
          <PaperPlaneRight size={16} />
        </Button>
      </div>
    </div>
  )
}
```

- [ ] **Step 3: Verificar tipos**

```
cd frontend && npx tsc -b
```
Confirmar el icono `PaperPlaneRight` existe en `@phosphor-icons/react` (o usar el nombre real disponible en la versión instalada — revisar otro archivo del repo que ya importe un ícono de "enviar" si existe, para reusar el mismo nombre).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/crm/whatsapp/ChatList.tsx frontend/src/pages/crm/whatsapp/ConversacionPanel.tsx
git commit -m "feat(whatsapp): lista de chats + panel de conversacion"
```

---

### Task 5: Ensamblar CrmWhatsAppTab.tsx

**Files:**
- Modify: `frontend/src/pages/crm/CrmWhatsAppTab.tsx`

**Interfaces:**
- Consumes: todo lo de Tasks 1-4.
- Produces: `CrmWhatsAppTab({ usuario }: { usuario: Usuario | null })` — el inbox completo y funcional.

- [ ] **Step 1: Implementar**

`frontend/src/pages/crm/CrmWhatsAppTab.tsx`:
```tsx
import { useState } from 'react'
import type { Usuario } from '../../types/auth'
import { normalizarRol } from '../../utils/roles'
import type { WhatsAppChat } from '../../types/whatsapp'
import { useWhatsAppChats, useWhatsAppCuentas } from '../../hooks/useWhatsApp'
import { CuentaSelector } from './whatsapp/CuentaSelector'
import { ConectarCuentaModal } from './whatsapp/ConectarCuentaModal'
import { ChatList } from './whatsapp/ChatList'
import { ConversacionPanel } from './whatsapp/ConversacionPanel'

export function CrmWhatsAppTab({ usuario }: { usuario: Usuario | null }) {
  const esAdmin = normalizarRol(usuario?.rol) === 'administrador'
  const [cuentaActivaId, setCuentaActivaId] = useState<number | 'todas'>('todas')
  const [chatActivo, setChatActivo] = useState<WhatsAppChat | null>(null)
  const [modalQrAbierto, setModalQrAbierto] = useState(false)

  const { data: cuentas = [] } = useWhatsAppCuentas()
  const { data: chats = [] } = useWhatsAppChats(cuentaActivaId === 'todas' ? undefined : cuentaActivaId)

  if (cuentas.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-center text-kyro-muted">
        <p className="text-sm">Todavía no hay ninguna cuenta de WhatsApp conectada.</p>
        {esAdmin && (
          <button
            type="button"
            onClick={() => setModalQrAbierto(true)}
            className="rounded-kyro bg-kyro-indigo px-4 py-2 text-sm font-medium text-white"
          >
            Conectar la primera cuenta
          </button>
        )}
        <ConectarCuentaModal open={modalQrAbierto} onClose={() => setModalQrAbierto(false)} />
      </div>
    )
  }

  return (
    <div className="flex h-[calc(100vh-220px)] flex-col gap-3">
      <CuentaSelector
        cuentas={cuentas}
        cuentaActivaId={cuentaActivaId}
        onSeleccionar={(id) => { setCuentaActivaId(id); setChatActivo(null) }}
        onAgregarNueva={() => setModalQrAbierto(true)}
        esAdmin={esAdmin}
      />

      <div className="flex flex-1 overflow-hidden rounded-kyro border border-kyro-border">
        <div className="w-80 shrink-0">
          <ChatList
            chats={chats}
            chatActivoId={chatActivo?.id ?? null}
            onSeleccionar={setChatActivo}
            mostrarBadgeCuenta={cuentaActivaId === 'todas'}
          />
        </div>
        <ConversacionPanel chat={chatActivo} />
      </div>

      <ConectarCuentaModal open={modalQrAbierto} onClose={() => setModalQrAbierto(false)} />
    </div>
  )
}
```

- [ ] **Step 2: Verificar tipos**

```
cd frontend && npx tsc -b
```
Esperado: limpio.

- [ ] **Step 3: Probar en navegador**

```
cd frontend && npm run dev
```
Ir a `/crm`, pestaña WhatsApp. Sin cuentas conectadas debe verse el estado vacío. Si hay credenciales de Evolution configuradas y se conecta una cuenta de prueba, confirmar que el QR se muestra y que al escanear pasa a "conectada".

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/crm/CrmWhatsAppTab.tsx
git commit -m "feat(whatsapp): ensamblar inbox completo en CrmWhatsAppTab"
```

---

## Fuera de alcance de este plan

- Envío de imágenes desde el composer (el backend ya soporta `tipo: 'imagen'` con `media_url`, pero el input de subida de archivos queda para una iteración siguiente).
- Vínculo con ficha CRM (F4).
- Notificaciones push de mensajes nuevos.
