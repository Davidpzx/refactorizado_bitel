# TICKET-029 — Paridad estructural: Historial admin + Comisiones Empresa + Ver Agente secciones

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si no alcanza, pedir división ANTES (el punto 3 es separable).
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Origen:** QA visual ticket-026 (04-qa-visual.md Bloque A, 04-qa-visual-C.md, 04-qa-visual-D1.md)

## Alcance
1. **Historial admin (HistorialPage):** añadir la columna Ganancia por fila y los badges de color de estado-efectivo que tiene el legacy (revisar la fila correspondiente del QA Bloque A para el detalle exacto).
2. **Comisiones Empresa:** el refactor fusionó las 2 páginas legacy en modales, perdiendo los bordes de color por sección y los banners explicativos. Sacar el contenido a secciones siempre visibles con el color-coding del legacy (verificado funcionalmente en ticket-012 — NO tocar la lógica, solo la presentación).
3. **Ver Agente (VerAgentePage):** faltan estructuralmente las secciones del legacy: Ficha RRHH completa (violeta) y Contactos de Emergencia (naranja) con TODOS sus campos, y el panel liquidación/boletas. DATO CLAVE del QA D1: la postulación pública (`/postular`, ticket-014) YA captura todos esos campos y existen en BD — es mostrar/editar datos existentes, no crear esquema. Usar CardTopAccent (violeta/naranja) del ticket-021.

## Criterio de aceptación
Las 3 pantallas comparadas contra sus capturas FireShot (013 para Ver Agente) sin secciones faltantes; datos reales visibles; `tsc`+`build` limpios; tests del dominio verdes si se toca backend (probablemente solo lectura de campos ya existentes en los endpoints).
