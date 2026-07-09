# STATUS — Plan de Paridad refactorizado_bitel vs sistema-rolando-salas

**Orquestador:** cuenta principal (david365dgxd), modelo Fable.
**Última actualización:** 2026-07-08 — **EJECUCIÓN AUTORIZADA POR EL USUARIO. OLA 1 EN VUELO.**

## Decisiones CONFIRMADAS por el usuario (2026-07-08)
- **DECISIÓN-001:** API externa de facturación, como el legacy (NO Greenter). Paridad total de funcionalidades.
- **DECISIÓN-002:** Migración a Phosphor APROBADA → ticket 018 deja de ser opcional (va después del 017).
- **DECISIÓN-003:** criterio recomendado aceptado tácitamente (identidad→configuracion_empresa, secretos/flags→sys_config, infra→.env).

## Rutas reales
- Legacy: `E:\laragon\www\sistema-rolando-salas` (227 archivos PHP)
- Refactorizado (objetivo): `C:\xampp\htdocs\refactorizado_bitel` (Laravel 12 + React 19)
- Capturas legacy: `C:\xampp\htdocs\refactor_principal\legacy\*.png` (33 FireShot)

## Estado por fase
- [x] **Fase 0** — 4 informes completos: `00-inventario-legacy.md` (dev2), `00-inventario-refactorizado.md` (dev3), `00-inventario-diseno.md` (titan), `00-informe-arquitectura.md` (Codex)
- [x] **Fase 1** — `01-gap-matrix.md` (matriz de brechas + 3 decisiones abiertas)
- [x] **Fase 2** — `02-master-plan.md` (5 fases, olas de ejecución balanceadas)
- [x] **Fase 3** — `plan/tickets/ticket-001.md` … `ticket-027.md` (27 tickets autocontenidos)
- [x] **Cierre** — `03-resumen-ejecutivo.md`
- [x] **GATE: confirmación del usuario** — dada el 2026-07-08 junto con DECISIÓN-001/002.
- [x] **TICKET-004 ENTREGADO, VERIFICADO E INTEGRADO** — commit `e75869f` (9 tests unitarios verdes re-ejecutados por el orquestador; suite Feature 346 verdes según worker). Nota del worker: solo había 1 punto de parsing PHP-side; el resto ya usaba tablas normalizadas/JSON_EXTRACT.
- [x] **TICKET-001 ENTREGADO, VERIFICADO E INTEGRADO** — commit `8214d85` (14 tests re-ejecutados por el orquestador, verdes; suite completa 370 según worker). Notas: migración con rama de adopción de tabla legacy; `migrate` contra MySQL del VPS sigue pendiente (lo cubre ticket 003); unique de fila global se garantiza en seeder/orden, no en BD (comentado en la migración).
- [x] **TICKET-003 ENTREGADO, INTEGRADO Y CERRADO EN VIVO** — commits `df34e94` + `34fa7f3`. El usuario confirmó acceso: SSH VPS (`~/.ssh/id_ed25519` = key Hostinger `david-sys-trading-local`, host `2.24.105.11`) + token Hostinger (MCP `hostinger-vps` ya cargado). El orquestador verificó en vivo (solo lectura, sin mutar nada): `migrate:status` → cero Pending; `migrar-chips-mal-guardados` (dry-run) → "Sin filas CHIP mal guardadas. Nada que migrar." **Sin pendientes reales.** Hallazgo distinto al asumido: la brecha no es migraciones sin correr, es que el código de HOY (ticket-001 etc.) aún no está *deployado* al VPS — deploy queda para cuando la Ola 1 esté integrada y validada.

## Acceso al VPS de producción (para el orquestador — NO delegar a workers sin supervisión)
- SSH: `ssh -i ~/.ssh/id_ed25519 root@2.24.105.11` (Ubuntu 24.04 + Dokploy, host Hostinger `srv1679080.hstgr.cloud`)
- Backend refactor: contenedor `erpcrmbitel-backend-5othkr...` (`docker ps` para el sufijo vivo — cambia por deploy), `APP_URL=refactor.kyrocodelabs.cloud`, `DB_DATABASE=migracion`
- Legacy (mundoandroid): contenedor `erpcrmbitel-mundoandroid-...`
- API/token: MCP `hostinger-vps` (`VPS_getVirtualMachinesV1` etc.) — VM id `1679080`
- Regla: lecturas (`migrate:status`, logs, dry-runs) libres; cualquier escritura real en producción (migrate --force, deploy, restart) se coordina con el usuario primero — es shared state de producción.
- [ ] **EN VUELO** (workers en background, sin commits — el orquestador revisa diff e integra):
  - dev2 + Opus 4.8 → TICKET-002 (cola comprobantes + snapshot fiscal) — task b6oh2tipx
  - dev3 + Sonnet 5 → TICKET-012 (verificar Comisiones Empresa) — task ba62kg1cq
  - titan + Sonnet 5 → TICKET-016 (ConfirmDialog + confirms) — task bbkqefwjn
- [ ] Cola de despacho por cuenta (siguiente ticket al liberarse, tras verificar el anterior):
  - dev2: ~~001~~ → 002 → 006 → 013
  - dev3: ~~004~~ → ~~003~~ → 012 → 014
  - titan: 016 → 017 → 018 (Phosphor, aprobado) → 019 → 024 → 015
  - luego: 005, 007, 008, 009, 010, 011, 020 (Opus), 021, 022, 023, 025
- [ ] QA final: 026 (visual, agentbrowser) y 027 (funcional, Opus)
- Regla de integración: cada entrega se verifica con `git diff` + tests antes de commit por ticket; conflictos de archivos evitados asignando dominios disjuntos por ola.

## Cuentas de la orquesta (verificadas 2026-07-08)
| Cuenta | Estado | Uso en ejecución |
|---|---|---|
| david (principal) | OK — orquestador | verifica e integra, NO implementa |
| titan (nashelitls) | OK | worker |
| dev2 (tutorialesdavid3) | OK | worker |
| dev3 (joan.achenquipa) | OK | worker |
| dev1 (comomellamotunosabes) | **CAÍDA** (org sin acceso a Claude Code) | fuera de rotación |

## Reglas vigentes
- Orquestador NO implementa código; a 70-80% de contexto degrada a Opus 4.8 y anota checkpoint aquí.
- Implementación: Sonnet 5 (mecánico/UI) u Opus 4.8 (complejo). NUNCA Fable. Regla 0.3: ningún ticket a medias.
- Workers: recordarles skills (headroom, superpowers, frontend-design si UI, agentbrowser si comparación visual) y modelo en CADA prompt — los prompts son autocontenidos (arrancan en frío).
- Diseño: replicar o mejorar identidad legacy "Ultra Dark Premium", nunca genérico; iconos con criterio = parte del criterio de aceptación.
- Al recoger cada entrega: revisar diff/resultado antes de darla por buena.
