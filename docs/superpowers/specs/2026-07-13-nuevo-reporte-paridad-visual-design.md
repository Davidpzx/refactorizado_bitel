# Paridad visual/UX de "Registrar Cuadre Diario" (reportes/nuevo)

## Contexto

El legacy PHP (`E:\laragon\www\sistema-rolando-salas\reportes\nuevo_reporte.php`, rol
`jefe_tienda`) y el SPA (`frontend/src/pages/reportes/NuevoReportePage.tsx`, ruta
`/reportes/nuevo`) ya comparten la misma estructura de fondo: 5 secciones de venta
(Postpago, Prepago/Chips, Equipos y Accesorios, Otros Ingresos Fijos, Apoyo
inter-tienda), el mismo bloque "Total Sistema (Consolidado)" y el mismo "Cuadre
Final" (Total en Cajón, Efectivo Esperado, Mi Efectivo, Lo Entregué/En Tienda,
Diferencia). Verificado navegando ambas apps en vivo (usuarios de prueba
`jefe_tienda`/`tienda`) el 2026-07-13.

**Decisión de alcance ya tomada por el usuario:** el SPA usa un patrón de carga de
ventas distinto al legacy — un modal único "Agregar Registro" que persiste cada
venta contra el backend de inmediato (arquitectura con tablas normalizadas:
`ventas`/`venta_equipos`/`venta_lineas`), mientras que el legacy llena 5 secciones
inline y manda todo en un solo POST al finalizar. **Ese patrón NO se toca** — se
considera una mejora arquitectónica deliberada del SPA, no un bug. Esta spec cubre
únicamente gaps cosméticos/de contenido, no el modelo de guardado.

---

## ⚠️ Corrección tras verificación contra el código (2026-07-13, tarde)

La lista original de 10 gaps se redactó observando el SPA en **modo admin**. Al leer
`frontend/src/pages/reportes/NuevoReportePage.tsx` y re-verificar la vista real de
**jefe_tienda**, varios "gaps" resultaron ya implementados o inaplicables. Estado real:

| # Gap original | Estado real | Acción |
|----------------|-------------|--------|
| 1. Bipay/Anypay embebido | `BipayConsole` ya se renderiza para `esTienda` (línea 1789); solo se auto-oculta cuando la tienda no tiene cuenta cajero configurada (legacy muestra placeholder + "Cerrar Jornada"). | Menor: mostrar placeholder cuando no hay cuenta. Baja prioridad. |
| 2. Guardar Borrador | El botón existe pero **solo en modo edición** (`esEdicion && esTienda`, líneas 1770-1778). En modo crear no hay botón explícito. | **Válido:** exponer también en modo crear. |
| 3. Modal CRM "Registro de Cliente" | La captura de cliente existe **inline** dentro del modal "Agregar Registro"; no existe el paso previo "modo consulta" del legacy. Endpoints `clientes-crm/{dni}` (buscar) y `clientes-crm` (guardar) ya existen en backend. | **Válido** pero medio: agregar el flujo de paso previo. |
| 4. Fecha nativa | **YA IMPLEMENTADO.** `<input type="date">` en línea 1796. Lo que se veía como "3 spinners" era el árbol de accesibilidad de un date input nativo. | ❌ Fuera de alcance (ya hecho). |
| 5. Label "Yape / Plin" → "Yape" | Confirmado, línea 2087. | **Válido** (trivial). |
| 6. Tienda oculta para jefe_tienda | **BUG FUNCIONAL.** El selector "Tienda \*" para no-admin se alimenta de una lista **hardcodeada** `TIENDAS` (líneas 57-60, solo Puno/Tacna) que puede **no incluir la tienda del jefe** → campo requerido imposible de llenar → **no puede enviar el reporte**. | **ALTA PRIORIDAD:** auto-fijar `tienda_id` a `usuario.tienda_id` y ocultar el selector para no-admin (como ya se hace con el campo Agente, líneas 1833-1841). |
| 7. Botón final renombrado | Confirmado "Guardar y Cerrar Caja · Empezar Nuevo" dorado (línea 2322). **Ojo:** el gating `!savedReporteId` (línea 2317) es **requerido** por la arquitectura incremental (cada venta se persiste antes de cerrar caja) — NO se puede quitar sin romper el flujo. | **Válido parcial:** renombrar a "Guardar Reporte Completo" + color azul; **conservar** el gating, solo reformular el texto de ayuda. |
| 8. Comprobante electrónico embebido | El SPA ya emite comprobantes **por venta** vía `PostVentaModal` (modal unificado ticket + SUNAT, línea 2345). Es consistente con la arquitectura incremental que el usuario decidió mantener. | ❌ Fuera de alcance (ya resuelto acorde a la arquitectura). |
| 9. Indicador de stock | `ChipStockBadge` ya muestra "N chips" (línea 1768); legacy dice "Sin stock ⌄". | Menor: ajustar wording/estilo. Baja prioridad. |
| 10. Quitar 4 tarjetas KPI | Confirmado (líneas 1855-1890). Se agregaron bajo el ticket de diseño **DIS-FX-09**; quitarlas revierte esa decisión. Usuario ya eligió quitarlas para paridad exacta. | **Válido** (decisión explícita del usuario). |

### Alcance real corregido (lo que el plan implementará)

**Prioridad alta (bug):**
- **G6 — Tienda auto-fijada y oculta para jefe_tienda.** Elimina la lista
  hardcodeada como fuente para no-admin; usa `usuario.tienda_id` y oculta el selector.

**Paridad de contenido (válidos):**
- **G10 — Quitar las 4 tarjetas KpiCard** en `/reportes/nuevo`.
- **G7 — Renombrar botón final** a "Guardar Reporte Completo" + color azul; conservar
  el gating de "guarda una venta primero" con texto reformulado.
- **G5 — Label "Yape / Plin" → "Yape".**
- **G2 — Botón "Guardar Borrador" también en modo crear** (hoy solo en edición).

**Menores / opcionales (baja prioridad, cosméticos):**
- **G9 — Wording del badge de stock** ("N chips" → estilo "Sin stock").
- **G1 — Placeholder de Bipay** cuando la tienda no tiene cuenta cajero.

**Más grande (evaluar aparte):**
- **G3 — Modal CRM "Registro de Cliente — Paso Previo"** (modo consulta del legacy).

**Fuera de alcance (ya implementado):** G4 (fecha nativa), G8 (comprobante vía
PostVentaModal).

## Gaps a cerrar

1. **Bipay/Anypay embebido** — el legacy muestra un panel Bipay/Anypay con saldos y
   un botón "Cerrar Jornada" en la parte superior del formulario de cuadre. En el
   SPA, Bipay/Anypay solo existe como página aparte en el menú lateral. Mover (o
   replicar) ese panel + botón al tope de `NuevoReportePage.tsx`.

2. **Botón "Guardar Borrador"** — presente en la cabecera del legacy junto a
   "Reg. Consulta CRM" y el indicador de stock. Ausente en el SPA. Agregarlo a la
   fila de acciones de cabecera (junto a "Cancelar").

3. **"Reg. Consulta CRM" (modal "Registro de Cliente — Paso Previo")** — en el
   legacy, al entrar a la pantalla (o vía botón de cabecera) se puede abrir un
   modal con: selector "Agente que atiende", DNI Cliente (con botones Buscar /
   Recuperar Cliente), Nombres y Apellidos autocompletados al buscar, Celular/
   WhatsApp con selector de operadora, y botón "Finalizar Consulta". Este flujo de
   "modo consulta" no existe en el SPA. Agregarlo como modal equivalente,
   reutilizando los endpoints de CRM que ya expone el backend si existen
   (confirmar en `backend/routes/api.php`; si no existen, crear el endpoint
   mínimo de búsqueda/registro de cliente por DNI).

4. **Fecha nativa** — el legacy usa un único `<input type="date">`. El SPA usa 3
   spinners separados (Día/Mes/Año). Reemplazar por un `<input type="date">`
   nativo (o un date-picker de un solo campo) para igualar la interacción.

5. **Label "Yape / Plin" → "Yape"** — el legacy solo dice "Yape" en el campo de
   Dinero No Físico. El SPA dice "Yape / Plin". Corregir el texto al literal del
   legacy.

6. **Selector de Tienda oculto para rol `jefe_tienda`** — en el SPA, un usuario
   `jefe_tienda` todavía ve un combobox "Tienda \*" vacío que debe llenar
   manualmente (aunque solo pertenezca a una tienda). En el legacy, la tienda del
   jefe de tienda es completamente implícita a su sesión — no hay selector. Para
   rol `jefe_tienda` (no para admin/gerente, que sí necesitan elegir tienda en
   "Modo Dios"), ocultar el selector y fijar la tienda automáticamente a la de su
   sesión.

7. **Botón final "Guardar Reporte Completo"** — el legacy usa ese texto exacto en
   azul. El SPA dice "Guardar y Cerrar Caja · Empezar Nuevo" en dorado, con un
   texto de ayuda "Agrega al menos una venta para habilitar el cierre de caja."
   que no tiene equivalente en el legacy (el botón del legacy no exige ventas
   previas para habilitarse). Cambiar label a "Guardar Reporte Completo", color a
   azul (paridad visual), y remover el requisito de "al menos una venta" salvo que
   el backend lo exija por regla de negocio (confirmar antes de quitar la
   validación; si el backend rechaza reportes vacíos, dejar el mensaje pero
   ajustar el texto para que no contradiga el legacy).

8. **"Agregar Comprobante Electrónico" dentro del formulario** — el legacy tiene
   un botón "Agregar Comprobante Electrónico" (con contador) al pie del
   formulario de cuadre, antes de "Guardar Reporte Completo". En el SPA, los
   comprobantes son solo una página aparte del menú ("Comprobantes"), sin control
   embebido en el formulario de reporte. Agregar el control equivalente dentro de
   `NuevoReportePage.tsx`.

9. **Indicador de stock** — el SPA ya muestra un badge "N chips" cerca del botón
   Cancelar (parcialmente equivalente al "Sin stock ⌄" del legacy). Revisar su
   contenido/wording y acercarlo al patrón del legacy (ej. desplegable con el
   detalle de qué está sin stock), sin necesidad de rehacer el componente si el
   dato mostrado ya es correcto — solo ajustar texto/estilo si diverge mucho.

10. **Quitar las 4 tarjetas KPI superiores** (Ventas / Ingresos / Salidas / Total
    Sistema) — no existen en el legacy en esta pantalla. Decisión explícita del
    usuario: eliminarlas para lograr paridad exacta, no dejarlas como "extra".

## Fuera de alcance (explícito)

- Rediseñar el modal "Agregar Registro" para volverlo inline por sección como el
  legacy. Se mantiene el modal actual.
- Cualquier cambio al backend de guardado incremental (`agregar-venta`,
  `borrador`, etc.) salvo lo estrictamente necesario para el punto 3 (CRM) si no
  existe endpoint de búsqueda de cliente por DNI.
- Cambios a roles/permisos backend (ya cubiertos en otro trabajo de este mismo
  ciclo, en el repo legacy).

## Verificación

- Comparar visualmente ambas pantallas logueado como `jefe_tienda`/rol tienda en
  ambos sistemas tras cada cambio (capturas lado a lado).
- Confirmar que `npm run lint` / `tsc` pasan limpios en `frontend/`.
- Confirmar que el flujo completo de guardado (modal "Agregar Registro" → guardar
  reporte) sigue funcionando end-to-end tras los cambios de layout.
