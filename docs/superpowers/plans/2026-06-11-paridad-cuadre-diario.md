# Paridad Cuadre Diario (Nuevo Reporte) — Implementation Plan

> **For agentic workers / Codex:** Este plan migra la pantalla "Nuevo Cuadre" a paridad 1:1 con el legacy PHP (`refactor_principal/reportes/nuevo_reporte.php`), con buenas prácticas y stack nuevo. Es el **piloto-patrón**: el sistema de diseño y los patrones que se creen aquí se reutilizan en el resto de la migración. Ejecutar tarea por tarea.

**Goal:** Dejar `NuevoReportePage.tsx` idéntica al legacy en UX/UI y comportamiento (cuadre, comisiones, stock, tickets, borrador), implementada limpia y reutilizable.

**Architecture:** React 18 + TS + react-hook-form + zod + TanStack Query. Se crean primitivos visuales premium reutilizables (`GlassPanel`, `SectionPanel`, `MoneyTotal`) que replican `includes/estilos.css` del legacy, y se corrige/expande la lógica del cuadre. Backend Laravel ya expone los endpoints necesarios; no se crea backend salvo que una verificación lo exija.

**Tech Stack:** React 18, TypeScript, TailwindCSS v4, react-hook-form, zod, @tanstack/react-query, lucide-react, SweetAlert2 (si no está, usar modal propio).

**Fuente de verdad legacy:** `C:/xampp/htdocs/refactor_principal/reportes/nuevo_reporte.php` (3029 líneas) y `includes/estilos.css`.

**Convención de verificación (este proyecto NO tiene test harness de frontend):**
- Cada tarea termina con: `cd frontend && npm run build` → debe decir `✓ built` sin errores TS.
- Verificación funcional = checklist manual en VPS tras redeploy del servicio `frontend` en Dokploy.
- Commits frecuentes, uno por tarea.

---

## File Structure

**Nuevos (sistema de diseño reutilizable):**
- `frontend/src/components/ui/GlassPanel.tsx` — panel oscuro premium (equiv. `.glass-panel`), con borde fino, sombra y `accentTop` opcional.
- `frontend/src/components/ui/SectionPanel.tsx` — panel de sección con header de color, badge de conteo y botón de agregar.
- `frontend/src/components/ui/MoneyTotal.tsx` — número grande tipo Orbitron/mono para totales.
- `frontend/src/pages/reportes/cuadre/` — subcomponentes del cuadre (filas, cuadre final, consolidado) para no inflar `NuevoReportePage.tsx`.
- `frontend/src/lib/cuadre.ts` — funciones puras de cálculo (esperado, diferencia, comisión, stock) + sus tipos.

**Modificados:**
- `frontend/src/pages/reportes/NuevoReportePage.tsx` — reescritura por secciones según tareas.
- `frontend/src/services/reportes.api.ts` — helper de inventario de tienda y chips si falta.

**Referencia (no tocar salvo nota):** `services/tickets.api.ts`, `components/BipayConsole.tsx`, `components/ChipStockBadge.tsx`.

---

## Paleta legacy (de `includes/estilos.css`) — usar literal

| Token | Valor |
|---|---|
| Fondo base | `#09090b` |
| Panel glass | `#18181b`, borde `rgba(255,255,255,0.08)`, sombra `0 4px 20px -2px rgba(0,0,0,.5)` |
| Header postpago | `#60a5fa` (azul) |
| Header prepago | `#22d3ee` (cyan) |
| Header equipos | `#fbbf24` (ámbar) |
| Header otros fijos | `#e4e4e7` (text-light) |
| Header apoyo | `#a78bfa` (morado) |
| Total sistema | acento `#22d3ee`, fondo `rgba(6,182,212,0.07)` |
| Cuadre final | borde `rgba(34,197,94,0.4)`, header verde |
| Glow destino "Entregué" | `#22c55e`, `box-shadow 0 0 20px rgba(34,197,94,.5)`, `scale(1.02)` |
| Glow destino "En Tienda" | `#fbbf24`, `box-shadow 0 0 20px rgba(251,191,36,.5)` |
| Fuente números | `'Orbitron', monospace` |

---

### Task 0: Primitivos visuales premium reutilizables

**Files:**
- Create: `frontend/src/components/ui/GlassPanel.tsx`
- Create: `frontend/src/components/ui/SectionPanel.tsx`
- Create: `frontend/src/components/ui/MoneyTotal.tsx`

- [ ] **Step 1: GlassPanel**

```tsx
import type { HTMLAttributes } from 'react'

interface GlassPanelProps extends HTMLAttributes<HTMLDivElement> {
  accentTop?: string   // color del borde superior (e.g. '#f59e0b'); omitir = sin acento
}

export function GlassPanel({ accentTop, className = '', style, ...props }: GlassPanelProps) {
  return (
    <div
      className={['rounded-xl', className].join(' ')}
      style={{
        background: '#18181b',
        border: '1px solid rgba(255,255,255,0.08)',
        boxShadow: '0 4px 20px -2px rgba(0,0,0,0.5)',
        ...(accentTop ? { borderTop: `3px solid ${accentTop}` } : {}),
        ...style,
      }}
      {...props}
    />
  )
}
```

- [ ] **Step 2: SectionPanel** (header de color + badge de conteo + botón agregar)

```tsx
import type { ReactNode } from 'react'
import { GlassPanel } from './GlassPanel'

export function SectionPanel({
  title, accent, count = 0, addLabel, onAdd, children, subtotal,
}: {
  title: string; accent: string; count?: number
  addLabel?: string; onAdd?: () => void; children: ReactNode; subtotal?: number
}) {
  return (
    <GlassPanel className="mb-4 overflow-hidden">
      <div className="flex items-center justify-between px-3 py-2 border-b"
           style={{ borderColor: 'rgba(255,255,255,0.06)' }}>
        <span className="text-sm font-bold flex items-center gap-2" style={{ color: accent }}>
          {title}
          {count > 0 && (
            <span className="text-[10px] font-bold rounded-full px-1.5 py-0.5"
                  style={{ background: `${accent}22`, color: accent }}>{count}</span>
          )}
        </span>
        {onAdd && (
          <button type="button" onClick={onAdd}
            className="text-xs font-semibold flex items-center gap-1 px-2 py-1 rounded-md transition-colors"
            style={{ color: accent, background: `${accent}14` }}>
            <span className="text-base leading-none">+</span> {addLabel}
          </button>
        )}
      </div>
      <div className="px-3 py-2">{children}</div>
      {subtotal !== undefined && count > 0 && (
        <div className="text-right text-xs font-semibold px-3 pb-2" style={{ color: accent }}>
          Subtotal: S/ {subtotal.toFixed(2)}
        </div>
      )}
    </GlassPanel>
  )
}
```

- [ ] **Step 3: MoneyTotal** (Orbitron)

```tsx
export function MoneyTotal({ value, color = '#22d3ee', size = '2rem' }: {
  value: number; color?: string; size?: string
}) {
  return (
    <span style={{ fontFamily: "'Orbitron', monospace", color, fontSize: size, fontWeight: 700 }}>
      S/ {value.toFixed(2)}
    </span>
  )
}
```

- [ ] **Step 4: Build**

Run: `cd frontend && npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/ui/GlassPanel.tsx frontend/src/components/ui/SectionPanel.tsx frontend/src/components/ui/MoneyTotal.tsx
git commit -m "feat(ui): primitivos premium GlassPanel/SectionPanel/MoneyTotal (paridad legacy)"
```

---

### Task 1: Funciones puras de cálculo del cuadre

**Files:**
- Create: `frontend/src/lib/cuadre.ts`

Centraliza la lógica que hoy está dispersa e incorrecta en la página. Replica el legacy.

- [ ] **Step 1: Tipos + cálculo de efectivo esperado (corrige el bug actual)**

Legacy (`nuevo_reporte.php`, función `calcular()`):
`total_no_fisico = yape + bipay + transferencia + retiro_bipay`
`efectivo_esperado = total_sistema − total_no_fisico − total_salidas`
(`caja_inicial` se muestra como "Total en Cajón" = `efectivo_esperado + caja_inicial`, no entra en el esperado de ventas.)

```ts
export interface CuadreInput {
  totalSistema: number      // suma de las 5 secciones de venta
  yape: number; bipay: number; transferencia: number; retiroBipay: number
  totalSalidas: number; cajaInicial: number; efectivoEntregado: number
}

export function calcularCuadre(i: CuadreInput) {
  const totalNoFisico = i.yape + i.bipay + i.transferencia + i.retiroBipay
  const efectivoEsperado = round2(i.totalSistema - totalNoFisico - i.totalSalidas)
  const totalEnCajon = round2(efectivoEsperado + i.cajaInicial)
  const diferencia = round2(i.efectivoEntregado - efectivoEsperado)
  return { totalNoFisico, efectivoEsperado, totalEnCajon, diferencia,
           requiereAprobacion: Math.abs(diferencia) > 10 }
}

export const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100
```

- [ ] **Step 2: Cálculo de comisión por línea (legacy `calcularComision`)**

Reglas legacy: comisión base viene del plan (`data-dni` normal, `data-ext` extranjero). Upgrade ⇒ 20.00 o 10.00 según diferencia de `fee_monto`. Se resta `COSTO_CHIP_FISICO = 1.00` salvo `migracion || upgrade || esim`.

```ts
export const COSTO_CHIP_FISICO = 1.00

export interface ComisionInput {
  comDni: number; comExt: number; esExtranjero: boolean
  esMigracion: boolean; esUpgrade: boolean; esEsim: boolean
  feePlanNuevo?: number; feePlanAnterior?: number
}

export function calcularComision(c: ComisionInput): number {
  let base = c.esExtranjero ? c.comExt : c.comDni
  if (c.esUpgrade) {
    const diff = (c.feePlanNuevo ?? 0) - (c.feePlanAnterior ?? 0)
    base = diff > 0 ? 20.0 : 10.0
  }
  if (!(c.esMigracion || c.esUpgrade || c.esEsim)) base -= COSTO_CHIP_FISICO
  return round2(base)
}
```

- [ ] **Step 3: Validación de stock de chips en vivo (legacy `stockActual`/`errorStock`)**

```ts
export interface StockChip { codigo: string; disponible: number }   // 'Propio' o código tienda apoyo

export function validarStock(stockInicial: StockChip[], consumos: Record<string, number>) {
  const restante = new Map(stockInicial.map(s => [s.codigo, s.disponible]))
  for (const [cod, qty] of Object.entries(consumos)) {
    restante.set(cod, (restante.get(cod) ?? 0) - qty)
  }
  const negativos = [...restante.entries()].filter(([, v]) => v < 0)
  return { restante, hayError: negativos.length > 0, negativos }
}
```

- [ ] **Step 4: Build + Commit**

Run: `cd frontend && npm run build` → `✓ built`
```bash
git add frontend/src/lib/cuadre.ts
git commit -m "feat(cuadre): funciones puras de cálculo (esperado/comisión/stock) según legacy"
```

---

### Task 2: Reestilizar layout a glass premium + consolidado de 5

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (reemplazar `Card`/`VentasSection` por `GlassPanel`/`SectionPanel`)

- [ ] **Step 1: Reemplazar contenedor y secciones**
  - Sustituir cada `<Card>` por `<GlassPanel>` y cada `<VentasSection>` por `<SectionPanel>` con su `accent`:
    - Postpago `#60a5fa`, Prepago `#22d3ee`, Equipos `#fbbf24`, Otros fijos `#e4e4e7`, Apoyo `#a78bfa` (apoyo se agrega en Task 4).
  - Eliminar el componente local `VentasSection` (queda obsoleto).

- [ ] **Step 2: Consolidado "Total Sistema" con 5 subtotales + Orbitron**
  - Reemplazar el bloque `bg-slate-800` por `GlassPanel` con `style={{ background:'rgba(6,182,212,0.07)' }}`, grid de 5 (Postpago, Prepago, Equipos, Otros, **Apoyo**) y total con `<MoneyTotal value={totalVentas} />`.

- [ ] **Step 3: Cuadre final premium**
  - `GlassPanel` con `style={{ border:'1px solid rgba(34,197,94,0.4)' }}`, header verde.
  - Mostrar "Total en Cajón" (`totalEnCajon`) y "Efectivo esperado" (`efectivoEsperado`) desde `calcularCuadre`.
  - Diferencia con color dinámico: `<0` rojo `#f87171`, `>0` ámbar `#fbbf24`, `==0` blanco.

- [ ] **Step 4: Build + verificación visual VPS**

Run: `cd frontend && npm run build` → `✓ built`
Verificación VPS (tras redeploy): la página se ve oscura con paneles glass, headers de colores, total cyan grande Orbitron, cuadre final con borde verde.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(cuadre): layout glass premium + consolidado 5 subtotales (paridad visual)"
```

---

### Task 3: Corregir fórmula de efectivo esperado (usar lib/cuadre)

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx:474-517` (bloque de totales)

- [ ] **Step 1: Reemplazar el cálculo actual**
  - Borrar `sumaEfectivoVentas`, `efectivo_esperado`, `diferencia`, `requiereAprobacion` actuales.
  - Calcular `totalSistema` = suma de los 5 subtotales (incluye apoyo de Task 4).
  - Llamar:
```ts
const { totalNoFisico, efectivoEsperado, totalEnCajon, diferencia, requiereAprobacion } =
  calcularCuadre({
    totalSistema: totalVentas,
    yape, bipay, transferencia, retiroBipay: retiro_bipay,
    totalSalidas: total_salidas, cajaInicial: caja_inicial,
    efectivoEntregado: efectivo_entregado,
  })
```
  - El "Total no físico" del panel derecho debe mostrar `totalNoFisico` (suma, **no** resta de retiro).

- [ ] **Step 2: Build + verificación VPS**

Run: `cd frontend && npm run build` → `✓ built`
Verificación VPS: con ventas que incluyan Yape/Bipay/Transferencia, el "efectivo esperado" baja por esos montos (antes no lo hacía). Comparar contra el legacy con los mismos números → deben coincidir.

- [ ] **Step 3: Commit**
```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "fix(cuadre): efectivo esperado resta medios digitales (paridad legacy)"
```

---

### Task 4: Sección "Ventas de Apoyo (otras tiendas)"

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx`
- Modify: `frontend/src/lib/cuadre.ts` (si hace falta tipo de consumo por tienda)

Legacy: bloque morado independiente (`#ventas_externas`) donde se venden chips de OTRA tienda; consume del stock de esa tienda (código), no del propio.

- [ ] **Step 1: Nuevo tipo de venta `APOYO`** en el `ventaSchema` (`z.enum([... ,'APOYO'])`) y `tienda_destino` requerido cuando `tipo_venta==='APOYO'`.

- [ ] **Step 2: SectionPanel morado** con filas (vendedor, plan prepago, cantidad, cobrado, tienda_destino). Reusar `LineaRow` extendido o crear `ApoyoRow` en `pages/reportes/cuadre/ApoyoRow.tsx`.

- [ ] **Step 3:** Incluir su subtotal en `totalVentas` y como 5ª celda del consolidado.

- [ ] **Step 4: Build + Commit**
Run: `cd frontend && npm run build` → `✓ built`
```bash
git add -A && git commit -m "feat(cuadre): sección Ventas de Apoyo (otras tiendas) — paridad legacy"
```

---

### Task 5: Destino del efectivo como toggles glow (2 opciones)

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (bloque destino, ~875-904)

Legacy: 2 botones-toggle: "Lo Entregué" (verde glow) y "En Tienda" (ámbar glow). Observaciones condicionales: ENTREGADO ⇒ input "a quién/operación" (requerido); EN_CAJA/TIENDA ⇒ textarea opcional.

- [ ] **Step 1:** Reemplazar los 3 radios planos por 2 botones tipo toggle. Mantener `destino_efectivo` en el form; mapear "En Tienda" al valor que el backend espera (revisar `ReporteController@store`: usar `EN_CAJA` o `TIENDA` según valida el backend — confirmar con `grep -i destino_efectivo backend/app/Http/Controllers/Api/ReporteController.php`).

```tsx
const DESTINOS = [
  { value: 'ENTREGADO', label: 'Lo Entregué', accent: '#22c55e' },
  { value: 'EN_CAJA',   label: 'En Tienda',   accent: '#fbbf24' },
] as const
// botón seleccionado: background accent, color oscuro, boxShadow `0 0 20px ${accent}80`, transform scale(1.02)
// no seleccionado: transparent, opacity .45, borde 2px accent
```

- [ ] **Step 2:** Observación condicional (ya existe para ENTREGADO; agregar textarea para EN_CAJA → guarda en `observaciones`/`obs_cuadre_caja` según convención del proyecto en `CLAUDE.md`).

- [ ] **Step 3: Build + Commit**
Run: `cd frontend && npm run build` → `✓ built`
```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(cuadre): destino efectivo como toggles glow (paridad legacy)"
```

---

### Task 6: Motor de comisiones + checkboxes postpago

**Files:**
- Modify: `frontend/src/pages/reportes/cuadre/LineaRow.tsx` (extraer `LineaRow` de la página a su propio archivo)
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx`

- [ ] **Step 1: Extraer `LineaRow`** a `pages/reportes/cuadre/LineaRow.tsx` (hoy vive inline en la página). Mantener API de props.

- [ ] **Step 2: Checkboxes legacy** en postpago: `migracion`, `upgrade` (muestra `plan_anterior`), `esim`, `extranjero`. Mapear a campos del schema (`es_migracion`, `es_upgrade`, `plan_anterior`, `es_esim`, `es_extranjero`). Añadir estos campos a `ventaSchema` si faltan.

- [ ] **Step 3: Cálculo de comisión en vivo** usando `calcularComision` y los datos de `usePlanesComisiones()`. El `ComisionPlan` debe exponer `comision_dni`, `comision_ext`, `fee_monto` (confirmar shape: `grep -n "comision\|fee" frontend/src/types/reporte.ts`). Escribir `comision_unitaria` por línea vía `setValue`.

- [ ] **Step 4: Build + verificación VPS**
Run: `cd frontend && npm run build` → `✓ built`
Verificación VPS: seleccionar un plan y marcar/desmarcar extranjero/migración/esim ⇒ la comisión calculada cambia igual que en el legacy.

- [ ] **Step 5: Commit**
```bash
git add -A && git commit -m "feat(cuadre): motor de comisiones en vivo + checkboxes postpago (paridad legacy)"
```

---

### Task 7: Equipos contra inventario (datalist + precio mínimo)

**Files:**
- Modify: `frontend/src/pages/reportes/cuadre/EquipoRow.tsx` (extraer de la página)
- Modify: `frontend/src/services/reportes.api.ts` o `inventario.api.ts` (helper stock de tienda si falta)

- [ ] **Step 1:** Cargar inventario de la tienda actual (endpoint `GET /v1/inventario` filtrado por tienda; confirmar params con `grep -n "index" backend/app/Http/Controllers/Api/InventarioController.php`). Construir un `<datalist>` por nombre; al elegir, fijar `inventario_tienda_id` (hidden) y `costo_snap`.

- [ ] **Step 2: Aviso de precio mínimo:** si `precio_venta < precio_min_autorizado` del ítem, mostrar alerta (SweetAlert2 si está instalado; si no, banner inline rojo) y marcar el campo. No bloquear el submit, solo advertir (igual que legacy).

- [ ] **Step 3: Total contado/cuotas:** CONTADO ⇒ suma `precio_venta`; CUOTAS ⇒ suma `efectivo_inicial` (inicial). Ajustar el `useEffect` de `monto_total`.

- [ ] **Step 4: Build + Commit**
Run: `cd frontend && npm run build` → `✓ built`
```bash
git add -A && git commit -m "feat(cuadre): equipos contra inventario con datalist y aviso precio mínimo"
```

---

### Task 8: Validación de stock de chips en vivo

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx`
- Reference: `frontend/src/components/ChipStockBadge.tsx` (endpoint `GET /v1/inventario-chips`)

- [ ] **Step 1:** Cargar stock inicial de chips (`/v1/inventario-chips`) y mapear a `StockChip[]` por código de origen ('Propio' + tiendas de apoyo).

- [ ] **Step 2:** Calcular `consumos` desde las filas prepago (propio) y apoyo (por `tienda_destino`); pasar a `validarStock`. Si `hayError`, deshabilitar el botón "Guardar Reporte" y mostrar "STOCK INSUFICIENTE".

- [ ] **Step 3:** Recolorear el badge de chips según restante (verde / ámbar ≤3 / rojo ≤0), reusando lógica del legacy `actualizarBadgeStock`.

- [ ] **Step 4: Build + verificación VPS**
Run: `cd frontend && npm run build` → `✓ built`
Verificación VPS: vender más chips de los disponibles ⇒ botón Guardar se bloquea con "STOCK INSUFICIENTE".

- [ ] **Step 5: Commit**
```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(cuadre): validación de stock de chips en vivo (paridad legacy)"
```

---

### Task 9: Generación de tickets (ingresos fijos + modal Ticket de Ingreso)

**Files:**
- Create: `frontend/src/pages/reportes/cuadre/TicketIngresoModal.tsx`
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx`
- Reference: `services/tickets.api.ts` (`POST /v1/tickets`), `pages/tickets/TicketImpresionPage.tsx` (impresión 80mm, ruta `/tickets/imprimir/:id`)

- [ ] **Step 1: Botones de ticket** junto a los ingresos fijos (Recarga/Pago Servicio/Krece/Tusamy) — ícono recibo — que abren el modal con esa categoría precargada.

- [ ] **Step 2: `TicketIngresoModal`** con: lista de ítems (descripción, monto), medios de pago (efectivo/yape/bipay/plin), cálculo de vuelto en vivo (`vuelto = recibido − total`). Validar `TicketPayload` (confirmar campos con `grep -n . frontend/src/types/ticket.ts`).

- [ ] **Step 3: Guardar e imprimir:** `ticketsApi.crear(payload)` ⇒ con el `id`, abrir `/tickets/imprimir/:id` en ventana nueva (impresión térmica existente).

- [ ] **Step 4: Build + verificación VPS**
Run: `cd frontend && npm run build` → `✓ built`
Verificación VPS: generar un ticket de ingreso ⇒ se guarda y abre la vista de impresión 80mm.

- [ ] **Step 5: Commit**
```bash
git add -A && git commit -m "feat(cuadre): generación de tickets de ingreso con impresión 80mm (paridad legacy)"
```

---

### Task 10: Borrador — fallback localStorage + rescate por timestamp

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (lógica de borrador ~439-472)

Legacy: autosave a nube + fallback a `localStorage` (`reporte_borrador_[tienda_id]`) si no hay red; al cargar, si el `timestamp` local es más nuevo que el de la nube, inyecta el local y luego sincroniza.

- [ ] **Step 1:** En `guardarBorrador`, si la llamada a la nube falla, persistir `{ form, salidaItems, timestamp: Date.now() }` en `localStorage[reporte_borrador_${tienda_id}]`.

- [ ] **Step 2:** Al montar, comparar timestamp nube vs local; ofrecer/recuperar el más reciente. Tras recuperar local, intentar re-sincronizar a la nube.

- [ ] **Step 3: Build + Commit**
Run: `cd frontend && npm run build` → `✓ built`
```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(cuadre): borrador con fallback localStorage y rescate por timestamp"
```

---

## Self-Review (cobertura vs spec del legacy)

- Layout 2 columnas glass + headers de color → Task 0, 2 ✅
- Consolidado 5 subtotales + Orbitron → Task 2, 4 ✅
- Fórmula efectivo esperado correcta → Task 1, 3 ✅
- Sección Apoyo → Task 4 ✅
- Destino efectivo toggles glow + obs condicional → Task 5 ✅
- Motor de comisiones + checkboxes postpago → Task 6 ✅
- Equipos vs inventario + precio mínimo → Task 7 ✅
- Validación stock chips viva → Task 8 ✅
- Tickets de ingreso + impresión → Task 9 ✅
- Borrador nube + fallback local → Task 10 ✅
- Consola Bipay/Anypay → ya existe (`BipayConsole`); fuera de alcance de este plan salvo que la verificación VPS revele brechas (registrar como follow-up).

**Pendiente de confirmar en código antes de implementar (cada uno es un `grep`, no bloquea el plan):**
- `ReporteController@store`: valores aceptados de `destino_efectivo` y nombres de campos del payload (Task 3/5).
- `frontend/src/types/reporte.ts`: shape de `ComisionPlan` (`comision_dni/ext`, `fee_monto`) (Task 6).
- `InventarioController@index`: filtro por tienda y campo de precio mínimo (Task 7).
- `frontend/src/types/ticket.ts`: `TicketPayload` (Task 9).
