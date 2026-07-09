# TICKET-014 — Verificar y cerrar: onboarding RRHH público

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design (si hay que construir UI)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada; si el faltante resulta grande, proponer subdivisión ANTES de construir.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`

## Contexto
El legacy tiene `public_onboarding.php`: formulario **público** de postulación/ficha RRHH (tabla `postulantes_temp`, luego aprobación en `gerencia/aprobar_postulante.php`). El refactor tiene `PostulacionPublicaPage` (`/postular`) + `PostulacionesPage` admin, que cubren la postulación (captura legacy 024). Duda del inventario de diseño: el legacy además usa este flujo como **"Registro de Datos RRHH"** (link púrpura del menú) para completar la ficha del agente ya contratado — no está claro si el refactor lo cubre.

## Alcance
1. Leer `public_onboarding.php` completo y mapear TODOS sus campos/modos contra `PostulacionPublicaPage` + `PostulanteController` + `AgenteDocumentoController`/perfil RRHH.
2. Veredicto con evidencia: qué modos cubre el refactor y cuáles no (¿postulación nueva? ¿completar ficha RRHH de agente existente? ¿subida de documentos?).
3. Cerrar el faltante si es acotado: p. ej. modo "completar ficha" con token/link por agente, reutilizando el formulario público existente.
4. Diseño: identidad pública kyro (`public-premium-*`), iconos con criterio.

## Criterio de aceptación
Informe de verificación con evidencia; cualquier faltante acotado cerrado y probado (flujo público completo → aparece para aprobación admin).
