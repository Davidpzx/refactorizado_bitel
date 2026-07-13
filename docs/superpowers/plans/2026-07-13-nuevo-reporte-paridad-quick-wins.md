# Paridad `reportes/nuevo` — Bug + Quick Wins · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar el bug de selección de tienda para jefe_tienda y 4 gaps de paridad visual/contenido en la pantalla `/reportes/nuevo` del SPA, sin tocar la arquitectura de guardado por modal.

**Architecture:** Cambios exclusivamente de frontend en un solo archivo React (`NuevoReportePage.tsx`), más ediciones de limpieza de imports/variables. No hay cambios de backend ni de contratos de API. Cada tarea es una edición quirúrgica verificada por typecheck + lint + build + verificación en navegador real (agent-browser, logueado como jefe_tienda).

**Tech Stack:** React 19 + TypeScript, react-hook-form + Zod, TanStack Query, Tailwind, Vite. Repo: `C:\xampp\htdocs\refactorizado_bitel` (rama `main`).

## Global Constraints

- **NO tocar** el patrón de modal "Agregar Registro" ni el flujo de guardado incremental (`savedReporteId`, `PostVentaModal`). Decisión del usuario.
- **NO tocar** el comportamiento para rol admin/gerente (`esAdminReporte`): siguen eligiendo tienda y agente ("Modo Dios").
- Archivo único a editar: `frontend/src/pages/reportes/NuevoReportePage.tsx` (salvo que un import quede muerto).
- Verificación por tarea: `npm run build` (corre `tsc -b` + `vite build`) y `npm run lint` deben pasar limpios; luego verificación visual en navegador logueado como jefe_tienda (`qatest.tienda.spa@mundoandroid.local` / `QaTest2026!`) en `https://app.kyrocodelabs.cloud/reportes/nuevo`.
- No existe test-harness de página para este componente; estos cambios son de paridad presentacional/comportamiento de UI. La señal de verificación es typecheck/lint/build + navegador, no RTL (montar el árbol de providers para una página de 2389 líneas sería frágil y de bajo valor — YAGNI).
- Commits pequeños, uno por tarea. Terminar mensajes de commit con las líneas Co-Authored-By / Claude-Session del repo.

---

### Task 1: G6 — Tienda auto-fijada y readonly para jefe_tienda (BUG)

**Contexto del bug:** para no-admin, el `<Select>` de tienda se alimenta de la
constante hardcodeada `TIENDAS` (solo Puno/Tacna). Si la tienda del jefe no está en esa
lista, el dropdown se ve **vacío** (aunque `setValue('tienda_id', usuario.tienda_id)` en
el `useEffect` de línea 982-992 sí fija el valor real) y, si el usuario lo toca, **reasigna
su reporte a otra tienda**. La corrección: para no-admin, no renderizar un dropdown
seleccionable; mostrar la tienda de sesión como campo readonly (igual que ya se hace con
el campo "Agente" en líneas 1833-1841) con un `<input type="hidden">` que mantiene
`tienda_id` registrado. Para admin no cambia nada.

`TIENDAS` **no** se elimina: sigue usándose en el modal de apoyo (línea 842).

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (bloque del campo Tienda, ~líneas 1799-1816)

**Interfaces:**
- Consumes: `usuario.tienda_id` (string), `esAdminReporte` (boolean), `register`, `setValue`, `errors`, `tiendasAdmin` — ya existentes.
- Produces: sin nuevas exportaciones; el form sigue enviando `tienda_id` string.

- [ ] **Step 1: Reemplazar el bloque del campo Tienda**

Buscar exactamente este bloque (dentro de la cabecera, después del campo Fecha):

```tsx
            <div>
              <Label htmlFor="tienda_id" className="text-xs font-medium text-kyro-body">Tienda *</Label>
              <Select
                id="tienda_id"
                {...register('tienda_id', {
                  onChange: () => {
                    if (esAdminReporte) setValue('agente_id', 0)
                  },
                })}
                className="kyro-input mt-1 h-8 text-sm"
              >
                <option value="">— Selecciona —</option>
                {esAdminReporte
                  ? tiendasAdmin.map(t => <option key={t.codigo} value={t.codigo}>{t.nombre} ({t.codigo})</option>)
                  : TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
              {errors.tienda_id && <p className="text-kyro-danger text-[10px] mt-0.5">{errors.tienda_id.message}</p>}
            </div>
```

Reemplazarlo por:

```tsx
            {esAdminReporte ? (
              <div>
                <Label htmlFor="tienda_id" className="text-xs font-medium text-kyro-body">Tienda *</Label>
                <Select
                  id="tienda_id"
                  {...register('tienda_id', {
                    onChange: () => { setValue('agente_id', 0) },
                  })}
                  className="kyro-input mt-1 h-8 text-sm"
                >
                  <option value="">— Selecciona —</option>
                  {tiendasAdmin.map(t => <option key={t.codigo} value={t.codigo}>{t.nombre} ({t.codigo})</option>)}
                </Select>
                {errors.tienda_id && <p className="text-kyro-danger text-[10px] mt-0.5">{errors.tienda_id.message}</p>}
              </div>
            ) : (
              <div>
                <Label className="text-xs font-medium text-kyro-body">Tienda</Label>
                <div className="text-xs text-kyro-muted bg-kyro-elevated rounded-kyro px-2 py-1.5 mt-1 w-full border border-kyro-border h-8 flex items-center">
                  <span className="text-kyro-subtle">Tienda:</span>&nbsp;
                  <span className="font-medium text-kyro-body">{usuario?.tienda_id ?? '—'}</span>
                  <input type="hidden" {...register('tienda_id')} />
                </div>
              </div>
            )}
```

- [ ] **Step 2: Verificar typecheck + lint + build**

Run: `cd frontend && npm run build && npm run lint`
Expected: build y lint sin errores. (Si `esAdminReporte`/`tiendasAdmin` quedaran sin uso, fallaría — pero ambos siguen usándose en el resto de la cabecera.)

- [ ] **Step 3: Verificar en navegador (jefe_tienda)**

Con agent-browser, logueado como jefe_tienda en `https://app.kyrocodelabs.cloud/reportes/nuevo`:
```bash
agent-browser --session spa open https://app.kyrocodelabs.cloud/reportes/nuevo && agent-browser --session spa screenshot ver.png
```
Expected: el campo "Tienda" muestra el código de la tienda del jefe como texto readonly (no un dropdown vacío), y ya no es posible cambiarlo a otra tienda. (Nota: el despliegue del SPA lo hace el pipeline habitual; la verificación en el dominio en vivo aplica tras desplegar. En local: `npm run dev` y abrir la ruta.)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "fix(reportes/nuevo): tienda readonly y autofijada para jefe_tienda (evita dropdown vacio y reasignacion)"
```

---

### Task 2: G10 — Quitar las 4 tarjetas KpiCard

**Contexto:** las 4 tarjetas resumen (Ventas/Ingresos/Salidas/Total Sistema, líneas
1850-1890) se agregaron bajo el ticket de diseño DIS-FX-09; el legacy no las tiene en
esta pantalla. Decisión explícita del usuario: quitarlas para paridad exacta. Al
quitarlas quedan muertos el import `KpiCard` (línea 19) y la variable `ventasBrutas`
(línea 1676) — ambos se usan **solo** en este bloque. Los iconos `Money/Coins/Export/Sigma`
y las variables `otrosFijos/total_salidas/totalSistema` siguen usándose en otras
secciones (Cuadre Final, Total Sistema, Caja Inicial) → **no** se tocan.

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (bloque KpiCard, import, variable muerta)

**Interfaces:**
- Consumes: nada nuevo.
- Produces: nada nuevo.

- [ ] **Step 1: Eliminar el bloque de las tarjetas KpiCard**

Borrar completo el bloque comentario + grid (desde `{/* ── Resumen del cuadre (KpiCard, DIS-FX-09) ── */}` hasta el `</div>` de cierre del grid), es decir estas líneas:

```tsx
        {/* ── Resumen del cuadre (KpiCard, DIS-FX-09) ──
            Oro reservado solo al Total Sistema (monto protagonista); ventas
            índigo, ingresos success, salidas danger. Sin sparkline: el cuadre
            no expone series históricas — el subtítulo da contexto real, no delta
            inventado. */}
        <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
          <KpiCard
            title="Ventas"
            value={ventasBrutas}
            monetary
            tone="indigo"
            icon={<Money size={18} />}
            subtitle={`${postpagoRows.length + prepagoRows.length + equipoRows.length + apoyoRows.length} registros`}
          />
          <KpiCard
            title="Ingresos"
            value={otrosFijos}
            monetary
            tone="success"
            accent="var(--color-kyro-success)"
            icon={<Coins size={18} />}
            subtitle={`${otrosRows.length} de flujo · fijos`}
          />
          <KpiCard
            title="Salidas"
            value={total_salidas}
            monetary
            tone="danger"
            accent="var(--color-kyro-danger)"
            icon={<Export size={18} />}
            subtitle={`${salidaItems.length} salida${salidaItems.length === 1 ? '' : 's'}`}
          />
          <KpiCard
            title="Total Sistema"
            value={totalSistema}
            monetary
            tone="gold"
            icon={<Sigma size={18} />}
            subtitle="Consolidado del día"
          />
        </div>
```

- [ ] **Step 2: Eliminar el import muerto de KpiCard**

Borrar la línea 19 completa:
```tsx
import { KpiCard } from '../../components/ui/KpiCard'
```

- [ ] **Step 3: Eliminar la variable muerta `ventasBrutas`**

Borrar la línea (~1676):
```tsx
  const ventasBrutas      = totalPostpago + totalPrepago + totalEquipos + totalApoyo
```

- [ ] **Step 4: Verificar typecheck + lint + build**

Run: `cd frontend && npm run build && npm run lint`
Expected: sin errores. Si el linter reporta `KpiCard`, `ventasBrutas`, `Money`, `Coins`, `Export` o `Sigma` como no usados, revisar: `Money/Coins/Export/Sigma` deben seguir importados (se usan en PanelHead de otras secciones); solo `KpiCard` y `ventasBrutas` se eliminan.

- [ ] **Step 5: Verificar en navegador**

Abrir `/reportes/nuevo`: ya no aparecen las 4 tarjetas entre la cabecera y el botón "Agregar Registro". El resto del layout intacto.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(reportes/nuevo): quitar tarjetas KPI resumen para paridad con legacy"
```

---

### Task 3: G7 — Renombrar botón final y color azul

**Contexto:** el botón de cierre dice "Guardar y Cerrar Caja · Empezar Nuevo" en dorado
(`variant="gold"`, línea 2322). El legacy dice "Guardar Reporte Completo" en azul. Se
renombra y se cambia a `variant="default"` (gradiente azul-índigo sólido). **Se conserva**
el gating `disabled={... || !savedReporteId}` y el texto de ayuda, porque el guardado
incremental requiere que exista al menos una venta persistida antes de cerrar caja; solo
se reformula el texto de ayuda para que no contradiga el flujo.

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (~líneas 2312-2330)

- [ ] **Step 1: Editar el bloque del botón de cierre (modo crear)**

Buscar:
```tsx
        {/* Modo crear: solo "Guardar y Cerrar Caja" */}
        {!esEdicion && (
          <div className="pb-8 space-y-2">
            <Button
              type="button"
              variant="gold"
              disabled={cerrandoCaja || stockInsuficiente || ventaSaving || !savedReporteId}
              onClick={handleCerrarCaja}
              className="w-full h-12 gap-2 text-base font-semibold"
            >
              <Receipt size={18} />
              {cerrandoCaja ? 'Cerrando caja...' : 'Guardar y Cerrar Caja · Empezar Nuevo'}
            </Button>
            {!savedReporteId && (
              <p className="text-[11px] text-kyro-muted text-center">
                Agrega al menos una venta para habilitar el cierre de caja.
              </p>
            )}
          </div>
        )}
```

Reemplazar por:
```tsx
        {/* Modo crear: "Guardar Reporte Completo" */}
        {!esEdicion && (
          <div className="pb-8 space-y-2">
            <Button
              type="button"
              variant="default"
              disabled={cerrandoCaja || stockInsuficiente || ventaSaving || !savedReporteId}
              onClick={handleCerrarCaja}
              className="w-full h-12 gap-2 text-base font-semibold"
            >
              <Receipt size={18} />
              {cerrandoCaja ? 'Guardando reporte...' : 'Guardar Reporte Completo'}
            </Button>
            {!savedReporteId && (
              <p className="text-[11px] text-kyro-muted text-center">
                Agrega al menos una venta para habilitar el guardado del reporte.
              </p>
            )}
          </div>
        )}
```

- [ ] **Step 2: Verificar typecheck + lint + build**

Run: `cd frontend && npm run build && npm run lint`
Expected: sin errores.

- [ ] **Step 3: Verificar en navegador**

Abrir `/reportes/nuevo`: el botón al pie dice "Guardar Reporte Completo" en azul sólido.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(reportes/nuevo): boton final 'Guardar Reporte Completo' en azul (paridad legacy)"
```

---

### Task 4: G5 — Label "Yape / Plin" → "Yape"

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (~línea 2087)

- [ ] **Step 1: Editar el label**

Buscar:
```tsx
                  ['yape',          'Yape / Plin'],
```
Reemplazar por:
```tsx
                  ['yape',          'Yape'],
```

- [ ] **Step 2: Verificar build**

Run: `cd frontend && npm run build`
Expected: sin errores.

- [ ] **Step 3: Verificar en navegador**

En "Dinero No Físico y Retiros" el primer campo dice "Yape".

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(reportes/nuevo): label 'Yape' (paridad legacy)"
```

---

### Task 5: G2 — Botón "Guardar Borrador" también en modo crear

**Contexto:** el botón "Guardar Borrador" (líneas 1775-1779) hoy solo aparece con
`esEdicion && esTienda`. El auto-guardado cada 60s (`guardarBorrador(true)`, línea 1596)
ya corre también en modo crear, así que `guardarBorrador(false)` es seguro en crear. El
legacy muestra "Guardar Borrador" en la pantalla de creación. Se amplía la condición para
que el botón aparezca para `esTienda` en ambos modos.

**Files:**
- Modify: `frontend/src/pages/reportes/NuevoReportePage.tsx` (~líneas 1775-1779)

- [ ] **Step 1: Ampliar la condición del botón Guardar Borrador**

Buscar:
```tsx
            {esEdicion && esTienda && (
              <Button variant="glassIndigo" type="button" className="gap-2" onClick={() => guardarBorrador(false)}>
                <Save size={15} /> Guardar Borrador
              </Button>
            )}
```
Reemplazar por:
```tsx
            {esTienda && (
              <Button variant="glassIndigo" type="button" className="gap-2" onClick={() => guardarBorrador(false)}>
                <Save size={15} /> Guardar Borrador
              </Button>
            )}
```

- [ ] **Step 2: Verificar typecheck + lint + build**

Run: `cd frontend && npm run build && npm run lint`
Expected: sin errores.

- [ ] **Step 3: Verificar en navegador**

Abrir `/reportes/nuevo` como jefe_tienda (modo crear): en la cabecera, junto a "Cancelar", aparece el botón "Guardar Borrador". Al hacer clic, se guarda sin error (mensaje `borradorMsg`).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/reportes/NuevoReportePage.tsx
git commit -m "feat(reportes/nuevo): boton Guardar Borrador tambien en modo crear (paridad legacy)"
```

---

## Self-Review (cobertura vs. spec corregido)

- **G6 (bug tienda)** → Task 1. ✔
- **G10 (quitar KPIs)** → Task 2. ✔
- **G7 (botón final)** → Task 3. ✔ (conserva gating, reformula ayuda — consistente con arquitectura incremental).
- **G5 (label Yape)** → Task 4. ✔
- **G2 (Guardar Borrador en crear)** → Task 5. ✔
- Fuera de alcance por decisión: G1, G3, G9 (menores/CRM) y G4/G8 (ya hechos). ✔
- Sin placeholders: cada paso tiene el código exacto a buscar/reemplazar. ✔
- Consistencia de tipos: no se introducen símbolos nuevos; se eliminan solo `KpiCard` (import) y `ventasBrutas` (var), ambos verificados como usados únicamente en el bloque removido. ✔
