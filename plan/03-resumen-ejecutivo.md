# 03 — Resumen ejecutivo del plan de paridad

**Fecha:** 2026-07-08 · **Orquestador:** cuenta principal (Fable). **Estado: PLAN COMPLETO — esperando confirmación del usuario para ejecutar. Nadie implementa nada hasta esa orden.**

## El hallazgo que cambió el plan

El refactorizado (Laravel 12 + React 19) está **mucho más cerca de la paridad de lo que decía la documentación previa**: los docs de junio eran más pesimistas que el código real (T1.2, T1.3, T2.5, `edicion_restaurada`, `log_ediciones_asistencia` — todos verificados CERRADOS contra código con tests). No hay tablas legacy sin equivalente a nivel de código, hay ~185 endpoints y ~66 tests Feature, y cero TODOs/stubs.

La paridad restante se concentra en **3 frentes**:

1. **Facturación electrónica SUNAT multi-emisor** — la brecha funcional #1. El legacy factura contra la API externa con config por tienda, cola con backoff, certificados PFX→PEM y links públicos HMAC (toda la inversión de esta semana); el refactor tiene Greenter global por `.env`, sin config por tienda, sin UI de configuración, sin links públicos ni NC/anulación completas.
2. **Identidad visual** — el port dark está ~85% fiel (el Dashboard es la referencia), pero hay ~30 `confirm()` nativos (ruptura #1 de identidad), 10 iconos mal mapeados en el sidebar + logo genérico, modo claro sin el azul corporativo Bitel, y la pantalla de cuadre (la más usada) sin su diseño característico.
3. **Verificaciones puntuales** — 5 gaps Tier 3/4 con estado desconocido, Comisiones Empresa posiblemente fusionada, onboarding RRHH dudoso, y una única pendiente operativa real: migraciones + comando de chips en el VPS.

## Alcance total

- **27 tickets** en `plan/tickets/` (autocontenidos: modelo, skills, regla anti-mitades, criterio de aceptación).
- **5 fases:** BD/esquema (3) → backend (7) → UI/diseño (12) → integraciones (3) → QA de paridad (2).
- **Ejecutores:** 7 tickets Opus 4.8 (migraciones SUNAT, cliente API + cola, configure-sunat, normalizador JSON, pantalla de cuadre, QA funcional) · 20 tickets Sonnet 5 (UI, verificaciones, runbooks). Ninguno en Fable, como manda la regla.
- **Complejidad global:** media. El riesgo grande no es volumen sino la migración de dominio SUNAT (correlativos concurrentes, snapshot fiscal, secretos) — por eso va en Opus con la secuencia BD→service→UI.
- **Congelado explícito:** Integrador Bipay on-premise (decisión 2026-07-04) — fuera del plan, sub-proyecto futuro.

## Diseño: qué se replica y qué se mejora

- **Replicar tal cual:** pantalla de cuadre (captura 004), hairlines de VerAgente (013) y Financieras (021), agrupación de Precios (007), sidebar azul Bitel en modo claro, modal PIN, modales de confirmación (hoy nativos → ConfirmDialog kyro).
- **Mantener como mejoras:** Login premium, Dashboard (referente del port), matriz/kardex/diagnóstico (extras del refactor), postulaciones como página propia, glass claro.
- **Iconografía:** corrección obligatoria de 10 mapeos + logo de marca (ticket 017); migración total a Phosphor con pesos fill/bold queda **opcional** (ticket 018, DECISIÓN-002).

## Riesgos principales (del informe de arquitectura)

1. Correlativos SUNAT duplicados en concurrencia → lock por serie/emisor en transacción (ticket 002).
2. Payload fiscal recalculado en retries → snapshot congelado (ticket 002).
3. Secretos (certificados, SOL, tokens) expuestos → storage privado + cifrado en reposo + logs redactados (tickets 001, 006).
4. Migraciones no corridas en VPS → features que compilan pero fallan en producción (ticket 003, prerequisito).
5. Parsing disperso del JSON `detalle` → normalizador único transversal (ticket 004).
6. Cron sin `schedule:run`/workers en deploy → matriz operativa (ticket 025).

## Decisiones que necesito de ti ANTES de la Ola 1

| # | Decisión | Recomendación |
|---|---|---|
| DECISIÓN-001 | SUNAT: ¿portar cliente de la **API externa** (como el legacy) o completar **Greenter** local? | **API externa** — es lo que el negocio usa hoy, multi-emisor probado, y toda la inversión de esta semana fue ahí. Greenter queda desactivado tras bandera. |
| DECISIÓN-002 | Iconos: ¿migración total a Phosphor (ticket 018)? | Hacer primero el quick-win (ticket 017, obligatorio); Phosphor después **sí** si quieres paridad de textura total — es port mecánico. |
| DECISIÓN-003 | Ownership `configuracion_empresa` vs `sys_config` vs `.env` por parámetro | Identidad visible → `configuracion_empresa`; secretos/flags técnicos → `sys_config`; infraestructura → `.env`. Congelar por escrito al arrancar. |

**La Ola 0 (10 tickets sin dependencias: 003, 004, 012, 013, 014, 015, 016, 017, 019, 024) puede arrancar sin esperar ninguna decisión.**

## Qué sigue

Nada se ejecuta hasta tu confirmación. Cuando la des, el orquestador reparte la Ola 0 entre las cuentas vivas (titan, dev2, dev3 — dev1 sigue caída) balanceando cuota, con Sonnet 5/Opus 4.8 según el ticket, y verifica cada entrega antes de darla por buena.
