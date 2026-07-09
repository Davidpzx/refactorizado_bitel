# 02 — Plan maestro de paridad

**Fecha:** 2026-07-08 · **Autor:** orquestador · **Insumo:** `01-gap-matrix.md`.
**Rutas:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas` · capturas `C:\xampp\htdocs\refactor_principal\legacy\*.png` (33).

**Reglas de ejecución (aplican a TODOS los tickets):**
- Ejecutores: **Sonnet 5** (mecánico/UI/CRUD/migraciones) u **Opus 4.8** (lógica compleja/arquitectura). **NUNCA Fable.** Codex/GPT como segunda opinión en piezas críticas.
- Skills obligatorias por ticket: `headroom` + `superpowers` siempre; `frontend-design` si toca UI; `agentbrowser` si requiere comparación visual en vivo.
- Regla 0.3: ningún ticket se deja a medias. Si el ejecutor estima que no le alcanza, pide subdivisión ANTES de empezar.
- Diseño: replicar la identidad "Ultra Dark Premium" del legacy o mejorarla sin perderla. Nunca genérico. Iconos con criterio semántico — parte del criterio de aceptación.
- Arquitectura (regla de Codex): portar **comportamiento**, no forma. Reglas de negocio en services/jobs/commands/middleware; controllers adaptan HTTP; nada de scripts procedurales ni `CREATE TABLE` en runtime.

---

## FASE 1 — Base de datos / esquema (+ decisiones previas)

**Gate de entrada:** el usuario confirma DECISIÓN-001 (SUNAT: API externa vs Greenter). Recomendación: API externa.

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 001 | Migración + modelo `facturacion_config` por tienda con fallback global | Opus 4.8 | DECISIÓN-001 |
| 002 | Migración cola de comprobantes con snapshot fiscal (payload congelado, intentos, backoff, api_doc_id) | Opus 4.8 | 001 |
| 003 | Runbook operativo: `migrate:status` en VPS + `inventario:migrar-chips-mal-guardados --force` | Sonnet 5 | acceso SSH |

## FASE 2 — Lógica de negocio / backend

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 004 | `ReporteDetalleNormalizer` único + tests (objeto/array/inválido/faltantes) — dependencia transversal | Opus 4.8 | — |
| 005 | Service cliente API facturación (2 pasos crear+send-sunat) + job/comando drenador con backoff | Opus 4.8 | 001, 002 |
| 006 | Endpoints config facturación + `configure-sunat` (upload certificado con conversión PFX→PEM, credenciales SOL, storage privado) | Opus 4.8 | 001 |
| 007 | Notas de crédito, anulación, descarga PDF/XML/CDR, reenviar | Sonnet 5 | 005 |
| 012 | Verificar y cerrar: Comisiones Empresa (¿fusionada en ComisionesPage o falta?) | Sonnet 5 | — |
| 013 | Verificar y cerrar gaps Tier 3/4: ajuste maestro inventario, GET token activo, recálculo masivo operativo, multi-IMEI/series_info, exports Excel | Sonnet 5 | — |
| 014 | Verificar y cerrar: onboarding RRHH público (`public_onboarding.php` vs `PostulacionPublicaPage`) | Sonnet 5 | — |

## FASE 3 — UI / diseño (fiel al original o mejorado, nunca genérico)

**Globales primero** (afectan todas las pantallas):

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 016 | `ConfirmDialog` kyro (identidad SweetAlert2 legacy) + reemplazo de los ~30 `confirm()` nativos | Sonnet 5 | — |
| 017 | Iconografía sidebar: corregir 10 mapeos (§4.1 inventario diseño) + logo marca (SVG dorado legacy / logo empresa) | Sonnet 5 | — |
| 018 | (OPCIONAL, DECISIÓN-002) Migración total a `@phosphor-icons/react` con pesos fill/bold | Sonnet 5 | 017, confirmación usuario |
| 019 | Modo claro: sidebar azul corporativo Bitel `rgba(0,53,128,.95)` + tabs/links dorados | Sonnet 5 | — |

**Por pantalla:**

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 020 | Réplica fiel de la pantalla de cuadre (Nuevo/Editar Reporte — captura 004; pantalla crítica) | Opus 4.8 | 016, QA visual previa |
| 021 | VerAgente: hairlines de color por card + botonera multicolor (+ verificar boletas/RRHH ya montadas) | Sonnet 5 | 016 |
| 022 | Financieras: 3 KPI con hairline + badges Krece/PayJoy + precios/saldos con color semántico | Sonnet 5 | 016 |
| 023 | Precios (RevisarStock): agrupación por tienda con chips + botón "Fijar" índigo por fila | Sonnet 5 | 017 |
| 024 | Asistencias: presentar rutas como pestañas (PageTabs) calcando la percepción del legacy | Sonnet 5 | — |
| 015 | Modal PIN de autorización con estética legacy (candado índigo, PIN spacing 8px) | Sonnet 5 | — |
| 009 | `ConfiguracionFacturacionPage`: wizard para gerente no técnico (réplica de c21a531) | Sonnet 5 | 006 |
| 010 | ComprobantesPage: paridad total (estados de cola, NC/anular/descargar/link WhatsApp) | Sonnet 5 | 005, 007, 008 |

## FASE 4 — Integraciones externas

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 008 | Links públicos CPE HMAC + vista pública sin sesión + impresión A4/a5/80mm/ticket | Sonnet 5 | 005 |
| 011 | Sync logo empresa → API facturación + pipeline `procesar_logo_upload` (flood-fill) en ConfiguracionPage | Sonnet 5 | 006 |
| 025 | Matriz operativa cron/scheduler: schedule:run, workers, `withoutOverlapping`, timezone Lima, logs | Sonnet 5 | 003 |

*(Integrador Bipay on-premise: CONGELADO por decisión 2026-07-04 — fuera de este plan; sub-proyecto futuro.)*

## FASE 5 — QA y validación de paridad

| Ticket | Título | Ejecutor | Deps |
|---|---|---|---|
| 026 | Verificación visual en vivo con agentbrowser: refactor corriendo vs 33 capturas FireShot, pantalla por pantalla | Sonnet 5 + agentbrowser | Fase 3 |
| 027 | Verificación funcional end-to-end de flujos críticos: cuadre completo, edición aprobada, traslado, emisión SUNAT en beta, cola con reintento | Opus 4.8 | Fases 1–4 |

---

## Orden de ejecución recomendado (ola por ola, balanceado entre cuentas)

1. **Ola 0 (sin dependencias, paralelizable ya):** 003, 004, 012, 013, 014, 016, 017, 019, 024, 015
2. **Ola 1 (tras DECISIÓN-001):** 001 → 002 → 005, 006 (en paralelo tras 001)
3. **Ola 2:** 007, 008, 009, 011, 021, 022, 023, 025
4. **Ola 3:** 010, 020, 018 (si se aprueba)
5. **Ola 4 (cierre):** 026, 027

**Balance:** las olas mezclan tickets Sonnet (volumen) con pocos Opus (complejidad); repartir de forma pareja entre las cuentas vivas (titan, dev2, dev3) según cuota disponible al momento — ningún agente debe cargar dos tickets Opus seguidos mientras otro está ocioso.

## Criterio global de "paridad lograda"

1. Cada fila de `01-gap-matrix.md` en estado ≠ OK tiene su ticket cerrado o una decisión explícita de no hacerlo.
2. QA visual (026) no encuentra pantallas "degradadas" ni "genéricas" — solo "fiel" o "mejorada".
3. QA funcional (027) pasa los flujos críticos con datos reales de prueba.
4. Cero `confirm()` nativos; cero iconos sin criterio; modo claro con identidad Bitel.
