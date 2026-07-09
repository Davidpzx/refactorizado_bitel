# TICKET-016 — `ConfirmDialog` kyro + eliminar los ~30 `confirm()` nativos

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada — el reemplazo es TODO o nada (un solo confirm() nativo restante = ticket incompleto). Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel` (frontend/src)

## Contexto
**Es la ruptura de identidad más visible del refactor** (inventario de diseño §2.2-2): ~30 llamadas a `confirm()`/`window.confirm()` nativo (eliminar agente/tienda/usuario/reporte/ticket/cuenta bipay/traslado, aprobar edición, recuperar tardanza, regenerar token, cerrar caja…). El legacy usa SweetAlert2 dark tematizado en el 100% de los casos: `background:'#18181b'`, `color:'#e4e4e7'`, botón confirmar del color de la acción (rojo eliminar, verde aprobar, dorado guardar), cancelar `#3f3f46`.

## Alcance
1. Crear `components/ui/confirm-dialog.tsx` sobre el `Dialog` existente (que ya tiene la identidad: hairline índigo→dorado, zinc-900/95, radio 16px): título, descripción, icono semántico por intención, `intent: 'danger' | 'success' | 'gold' | 'indigo'` que colorea el botón de confirmar; cancelar siempre `#3f3f46`. Exponer helper imperativo `confirmDialog({...}): Promise<boolean>` (provider + hook) para que el reemplazo en handlers async sea 1 línea.
2. `grep -rn "confirm(" frontend/src` y reemplazar **todas** las ocurrencias, eligiendo intención e icono correctos por acción (eliminar → rojo + Trash2; aprobar → verde + Check; token → índigo + KeyRound; etc.).
3. Lista de archivos tocados en el PR con la intención elegida por cada uno.
4. Verificación: `grep` final sin resultados de `window.confirm|confirm(` (excluyendo falsos positivos tipo `confirmar` en español).

## Criterio de aceptación
Cero confirms nativos (grep limpio); demo manual de 3 flujos (eliminar, aprobar, acción dorada) con el modal kyro; los iconos de cada confirmación son semánticos, no genéricos.
